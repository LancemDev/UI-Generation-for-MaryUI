<?php

namespace App\Jobs;

use App\Exceptions\AiServiceException;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Services\GenerationPipeline;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answers one user message in the background and fills in the placeholder
 * assistant message. The chat UI polls the message; the preview panel polls
 * the project's generation state.
 */
class ProcessChatMessage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    // Waiting behind another generation for the same project is fine; a real error is not retried.
    public int $maxExceptions = 1;

    public function __construct(
        public int $projectId,
        public int $assistantMessageId,
        public ?string $focusComponent = null,
    ) {
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function middleware(): array
    {
        // One generation per project at a time: they write to the same container.
        return [(new WithoutOverlapping("project:{$this->projectId}"))->releaseAfter(5)->expireAfter($this->timeout + 60)];
    }

    public function handle(GenerationPipeline $pipeline): void
    {
        $assistant = ChatMessage::find($this->assistantMessageId);
        $project = Project::find($this->projectId);
        if (!$assistant || !$project) {
            return;
        }

        $userMessage = ChatMessage::where('chat_thread_id', $assistant->chat_thread_id)
            ->where('role', 'user')
            ->where('id', '<', $assistant->id)
            ->latest('id')
            ->firstOrFail();

        $project->setGenerationState([
            'is_generating' => true,
            'stage' => 'thinking',
            'prompt' => $userMessage->content,
            'message_id' => $assistant->id,
            'started_at' => now()->toIso8601String(),
        ]);

        $result = $pipeline->run(
            $project,
            $userMessage->content,
            $this->history($assistant->chat_thread_id, $userMessage->id),
            $this->focusComponent,
            function (string $stage) use ($project, $assistant) {
                $project->setGenerationState(['stage' => $stage]);
                $assistant->update(['status' => $stage]);
            },
        );

        $content = $result->reply;
        if ($result->errors) {
            $content .= "\n\nThe preview still has errors after " . $result->repairAttempts . " automatic fix attempt(s):\n- "
                . implode("\n- ", $result->errors);
        }

        $assistant->update(['content' => $content, 'status' => 'complete', 'metadata' => $result->toArray()]);

        $state = ['is_generating' => false, 'stage' => $result->errors ? 'has_errors' : 'ready', 'message_id' => $assistant->id];
        if ($result->hasChanges()) {
            $state += [
                'component_name' => $result->primaryComponent(),
                'preview_url' => $result->previewUrl,
                'preview_ready' => true,
                'completed_at' => now()->toIso8601String(),
            ];

            $names = implode(', ', array_keys($result->components));
            $result->errors
                ? NotificationService::warning('Generated with errors', "{$names} still has errors in the preview.", $project->id, [], $project->user_id)
                : NotificationService::success('Components ready', "Updated {$names}.", $project->id, [], $project->user_id);
        }
        $project->setGenerationState($state);
    }

    public function failed(Throwable $e): void
    {
        Log::error('[CHAT] Generation failed', ['message_id' => $this->assistantMessageId, 'error' => $e->getMessage()]);

        $reason = $e instanceof AiServiceException ? $e->getMessage() : 'Something went wrong while building your request.';

        ChatMessage::whereKey($this->assistantMessageId)->update([
            'content' => "Sorry, I couldn't finish that. {$reason}",
            'status' => 'error',
        ]);

        $project = Project::find($this->projectId);
        if ($project) {
            $project->setGenerationState(['is_generating' => false, 'stage' => 'failed']);
            NotificationService::error('Generation failed', $reason, $project->id, [], $project->user_id);
        }
    }

    /**
     * Earlier turns of the conversation, oldest first, with any feedback attached.
     */
    private function history(int $threadId, int $beforeId): array
    {
        return ChatMessage::where('chat_thread_id', $threadId)
            ->where('id', '<', $beforeId)
            ->whereIn('role', ['user', 'assistant'])
            ->whereIn('status', ['sent', 'complete'])
            ->where('content', '!=', '')
            ->latest('id')
            ->limit(config('skylarr.ai.history_messages'))
            ->get()
            ->reverse()
            ->map(fn (ChatMessage $message) => [
                'role' => $message->role,
                'content' => $message->content,
                'feedback' => $message->feedback,
            ])
            ->values()
            ->all();
    }
}
