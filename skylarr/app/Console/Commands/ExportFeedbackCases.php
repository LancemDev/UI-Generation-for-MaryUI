<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use Illuminate\Console\Command;

/**
 * Turns rated chat turns into draft eval cases. Review the output, add
 * `expect` rules, and move the useful ones into evals/cases.json.
 */
class ExportFeedbackCases extends Command
{
    protected $signature = 'skylarr:export-feedback
        {--rating=negative : negative, positive or all}
        {--since= : Only feedback after this date (e.g. 2026-10-01)}
        {--output=evals/feedback-cases.json : Output path, relative to the app root}';

    protected $description = 'Export rated generations as draft eval cases';

    public function handle(): int
    {
        $rating = $this->option('rating');

        $rated = ChatMessage::where('role', 'assistant')
            ->whereNotNull('feedback')
            ->when($rating !== 'all', fn ($query) => $query->where('feedback', $rating))
            ->when($this->option('since'), fn ($query, $since) => $query->where('updated_at', '>=', $since))
            ->orderBy('id')
            ->get();

        $cases = $rated->map(function (ChatMessage $assistant) {
            // Every user turn up to the rated reply, so follow-ups replay with context.
            $turns = ChatMessage::where('chat_thread_id', $assistant->chat_thread_id)
                ->where('role', 'user')
                ->where('id', '<', $assistant->id)
                ->orderBy('id')
                ->pluck('content')
                ->all();

            return [
                'id' => "feedback-{$assistant->id}",
                'turns' => $turns,
                'expect' => ['changed_min' => count($assistant->metadata['components'] ?? []) > 0 ? 1 : 0],
                'notes' => [
                    'rating' => $assistant->feedback,
                    'comment' => $assistant->feedback_comment,
                    'reply' => $assistant->content,
                    'components' => array_column($assistant->metadata['components'] ?? [], 'component'),
                    'errors' => $assistant->metadata['errors'] ?? [],
                ],
            ];
        })->filter(fn ($case) => $case['turns'] !== [])->values();

        file_put_contents(base_path($this->option('output')), json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->info("Exported {$cases->count()} cases to {$this->option('output')}");

        return self::SUCCESS;
    }
}
