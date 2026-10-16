<?php

namespace App\Services;

/**
 * What one chat message produced: the reply, the components written, and anything still broken.
 */
class GenerationResult
{
    /** @var array<string, array{op: string, component: string, summary: string, route: string}> */
    public array $components = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    public int $repairAttempts = 0;

    public ?string $previewUrl = null;

    /** @var array<string, int> */
    public array $usage = [];

    /**
     * Milliseconds per stage: ai (wall time of AI service calls), model (time the
     * provider spent generating, as reported by the service), first_reply (until
     * the first streamed reply text), container (start/claim), write_check
     * (writing files and rendering them), total.
     *
     * @var array<string, int>
     */
    public array $timings = [];

    /** Model calls made by the AI service (edit fallbacks and static repairs add calls). */
    public int $aiCalls = 0;

    public function __construct(
        public string $reply,
        public string $model = '',
    ) {
    }

    public function recordAction(array $action): void
    {
        $name = $action['component'];
        // Keep the first op: a component created then repaired is still a "create".
        $this->components[$name] = [
            'op' => $this->components[$name]['op'] ?? $action['op'],
            'component' => $name,
            'summary' => $action['summary'],
            'route' => '/' . DockerPreviewService::kebab($name),
        ];
    }

    public function addUsage(array $usage): void
    {
        foreach ($usage as $key => $value) {
            $this->usage[$key] = ($this->usage[$key] ?? 0) + $value;
        }
    }

    public function addTiming(string $stage, float $milliseconds): void
    {
        $this->timings[$stage] = ($this->timings[$stage] ?? 0) + (int) round($milliseconds);
    }

    /**
     * Run a callback and add its duration to a stage.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function time(string $stage, callable $callback): mixed
    {
        $startedAt = microtime(true);
        try {
            return $callback();
        } finally {
            $this->addTiming($stage, (microtime(true) - $startedAt) * 1000);
        }
    }

    public function primaryComponent(): ?string
    {
        return array_key_first($this->components);
    }

    public function hasChanges(): bool
    {
        return $this->components !== [];
    }

    public function toArray(): array
    {
        return [
            'components' => array_values($this->components),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'repair_attempts' => $this->repairAttempts,
            'model' => $this->model,
            'usage' => $this->usage,
            'timings' => $this->timings,
            'ai_calls' => $this->aiCalls,
        ];
    }
}
