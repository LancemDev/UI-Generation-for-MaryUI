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
        ];
    }
}
