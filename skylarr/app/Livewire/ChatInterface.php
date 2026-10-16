<?php

namespace App\Livewire;

use App\Jobs\ProcessChatMessage;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Project;
use App\Services\TemplateLibrary;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Mary\Traits\Toast;

class ChatInterface extends Component
{
    use Toast;

    /** Assistant message statuses that mean the job is still working. */
    public const PENDING_STATUSES = ['queued', 'thinking', 'building', 'fixing'];

    public string $message = '';
    #[Locked]
    public ?int $threadId = null;
    #[Locked]
    public ?int $projectId = null;
    public array $messages = [];
    public ?string $selectedComponentName = null;
    public bool $showComponentSelect = false;
    public bool $showTemplates = false;

    /** Part of the selected component the next change should be limited to. */
    public ?string $selection = null;

    private const MAX_SELECTION_CHARS = 4000;

    public function mount(?int $threadId = null, ?int $projectId = null): void
    {
        $this->threadId = $threadId;
        $this->projectId = $projectId;

        if ($this->projectId) {
            $this->loadThread();
        }
    }

    /**
     * With live updates on, generation progress is pushed over the project's
     * private channel instead of polled.
     */
    public function getListeners(): array
    {
        return config('skylarr.realtime') && $this->projectId
            ? ["echo-private:project.{$this->projectId},GenerationUpdated" => 'refreshPending']
            : [];
    }

    protected function loadThread(): void
    {
        $thread = ChatThread::where('user_id', Auth::id())
            ->where('project_id', $this->projectId)
            ->when($this->threadId, fn ($query) => $query->whereKey($this->threadId))
            ->latest('updated_at')
            ->first();

        $thread ??= ChatThread::create([
            'user_id' => Auth::id(),
            'project_id' => $this->projectId,
            'title' => 'New chat',
        ]);

        $this->threadId = $thread->id;
        $this->messages = ChatMessage::where('chat_thread_id', $thread->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ChatMessage $m) => $this->present($m))
            ->all();
    }

    public function sendMessage(): void
    {
        $content = trim($this->message);
        if ($content === '' || $this->isWaiting() || !$this->ownsProject()) {
            return;
        }

        $this->message = '';

        $userMessage = ChatMessage::create([
            'chat_thread_id' => $this->threadId,
            'role' => 'user',
            'content' => $content,
            'status' => 'sent',
        ]);
        $assistantMessage = ChatMessage::create([
            'chat_thread_id' => $this->threadId,
            'role' => 'assistant',
            'content' => '',
            'status' => 'queued',
        ]);
        ChatThread::whereKey($this->threadId)->update(['updated_at' => now()]);

        $this->messages[] = $this->present($userMessage);
        $this->messages[] = $this->present($assistantMessage);

        $selection = $this->selectedComponentName ? $this->selection : null;
        ProcessChatMessage::dispatch($this->projectId, $assistantMessage->id, $this->selectedComponentName, $selection);
        $this->selection = null;

        $this->dispatch('chat-scrolled');
        $this->dispatch('generation-started');
    }

    /**
     * Polled while a reply is pending: pull finished/progressing messages from the DB.
     */
    public function refreshPending(): void
    {
        if (!$this->ownsProject()) {
            return;
        }

        $pendingIds = collect($this->messages)
            ->filter(fn ($m) => in_array($m['status'], self::PENDING_STATUSES, true))
            ->pluck('id');

        if ($pendingIds->isEmpty()) {
            return;
        }

        $fresh = ChatMessage::whereIn('id', $pendingIds)
            ->where('chat_thread_id', $this->threadId)
            ->get()
            ->keyBy('id');

        foreach ($this->messages as $index => $message) {
            if (!$fresh->has($message['id'])) {
                continue;
            }
            $updated = $this->present($fresh[$message['id']]);
            if ($updated !== $message) {
                $this->messages[$index] = $updated;
                if (!in_array($updated['status'], self::PENDING_STATUSES, true)) {
                    $this->dispatch('chat-scrolled');
                    $this->dispatch('generation-finished');
                }
            }
        }
    }

    /**
     * Insert a starter template. It goes through the same queued job as a
     * message, without an AI call, so the preview and history behave the same.
     */
    public function useTemplate(string $templateId): void
    {
        if ($this->isWaiting() || !$this->ownsProject()) {
            return;
        }

        try {
            $template = app(TemplateLibrary::class)->find($templateId);
        } catch (InvalidArgumentException) {
            return;
        }

        $userMessage = ChatMessage::create([
            'chat_thread_id' => $this->threadId,
            'role' => 'user',
            'content' => "Start from the {$template['title']} template",
            'status' => 'sent',
        ]);
        $assistantMessage = ChatMessage::create([
            'chat_thread_id' => $this->threadId,
            'role' => 'assistant',
            'content' => '',
            'status' => 'queued',
        ]);
        ChatThread::whereKey($this->threadId)->update(['updated_at' => now()]);

        $this->messages[] = $this->present($userMessage);
        $this->messages[] = $this->present($assistantMessage);
        $this->showTemplates = false;

        ProcessChatMessage::dispatch($this->projectId, $assistantMessage->id, templateId: $template['id']);

        $this->dispatch('chat-scrolled');
        $this->dispatch('generation-started');
    }

    public function getTemplatesProperty(): array
    {
        return app(TemplateLibrary::class)->all();
    }

    public function toggleTemplates(): void
    {
        $this->showTemplates = !$this->showTemplates;
    }

    /**
     * An element picked in the preview (by route path) or text selected in the
     * code editor (by component name). The next message is scoped to it.
     */
    #[On('element-selected')]
    public function selectElement(string $snippet, ?string $path = null, ?string $component = null): void
    {
        $components = collect($this->project()?->getComponents() ?? []);
        $match = $component
            ? $components->firstWhere('name', $component)
            : $components->firstWhere('route', '/' . ltrim((string) $path, '/'));

        if (!$match || trim($snippet) === '') {
            return;
        }

        $this->selectedComponentName = $match['name'];
        $this->selection = Str::limit(trim($snippet), self::MAX_SELECTION_CHARS, '');
        $this->showComponentSelect = false;
    }

    public function clearSelection(): void
    {
        $this->selection = null;
    }

    public function isWaiting(): bool
    {
        return collect($this->messages)->contains(fn ($m) => in_array($m['status'], self::PENDING_STATUSES, true));
    }

    public function getComponentsProperty(): array
    {
        return $this->project()?->getComponents() ?? [];
    }

    public function toggleComponentSelect(): void
    {
        $this->showComponentSelect = !$this->showComponentSelect;
    }

    public function selectComponent(?string $componentName): void
    {
        $this->selectedComponentName = $componentName;
        $this->selection = null;
        $this->showComponentSelect = false;
    }

    public function submitFeedback(int $messageId, string $feedbackType): void
    {
        if (!in_array($feedbackType, ['positive', 'negative'], true) || !$this->ownsProject()) {
            return;
        }

        $updated = ChatMessage::whereKey($messageId)
            ->where('chat_thread_id', $this->threadId)
            ->where('role', 'assistant')
            ->update(['feedback' => $feedbackType]);

        if (!$updated) {
            return;
        }

        foreach ($this->messages as $key => $message) {
            if ($message['id'] === $messageId) {
                $this->messages[$key]['feedback'] = $feedbackType;
            }
        }

        $feedbackType === 'positive'
            ? $this->success('Thanks for the feedback!')
            : $this->info("Thanks. I'll take that into account in this conversation.");
    }

    public function render()
    {
        return view('livewire.chat-interface', ['waiting' => $this->isWaiting(), 'realtime' => config('skylarr.realtime')]);
    }

    private function present(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'status' => $message->status,
            'feedback' => $message->feedback,
            'components' => $message->metadata['components'] ?? [],
            'created_at' => $message->created_at?->toDateTimeString(),
        ];
    }

    private function project(): ?Project
    {
        return $this->projectId ? Project::where('user_id', Auth::id())->find($this->projectId) : null;
    }

    private function ownsProject(): bool
    {
        return $this->threadId !== null
            && $this->project() !== null
            && ChatThread::whereKey($this->threadId)
                ->where('user_id', Auth::id())
                ->where('project_id', $this->projectId)
                ->exists();
    }
}
