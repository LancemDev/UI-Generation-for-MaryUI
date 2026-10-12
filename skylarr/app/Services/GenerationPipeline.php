<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Handles one chat message end to end:
 *
 *   1. one AI call with the project's real component source as context
 *   2. write any created/updated components into the preview container
 *   3. render each one; send failures back to the model, up to max_repair_attempts
 *
 * Used by the ProcessChatMessage job and the skylarr:eval command.
 */
class GenerationPipeline
{
    public function __construct(
        private AiGateway $ai,
        private DockerPreviewService $preview,
    ) {
    }

    /**
     * @param list<array{role: string, content: string, feedback?: ?string}> $history
     * @param callable(string): void|null $onStage Called with thinking|building|fixing
     */
    public function run(
        Project $project,
        string $message,
        array $history = [],
        ?string $focusComponent = null,
        ?callable $onStage = null,
    ): GenerationResult {
        $stage = $onStage ?? fn (string $stage) => null;
        $stage('thinking');

        $hasContainer = $project->container_id && $this->preview->isContainerRunning($project->container_id);
        $request = [
            'message' => $message,
            'history' => $history,
            'components' => $this->componentContext($project, $hasContainer),
            'focus_component' => $focusComponent,
        ];

        $response = $this->ai->generate($request);
        $result = new GenerationResult($response['reply'], $response['model'] ?? '');
        $result->addUsage($response['usage'] ?? []);
        $result->warnings = $response['warnings'] ?? [];

        if (empty($response['actions'])) {
            return $result;
        }

        $stage('building');
        $result->previewUrl = $this->preview->getOrCreateProjectContainer($project);
        $project->refresh();

        $this->apply($project, $response['actions'], $result);
        $errors = $this->validate($project, array_keys($result->components));

        $maxAttempts = config('skylarr.ai.max_repair_attempts');
        while ($errors && $result->repairAttempts < $maxAttempts) {
            $result->repairAttempts++;
            $stage('fixing');
            Log::info('[PIPELINE] Repairing components', ['project_id' => $project->id, 'errors' => $errors]);

            $repair = $this->ai->generate([
                ...$request,
                'components' => $this->componentContext($project, true),
                'errors' => $errors,
            ]);
            $result->addUsage($repair['usage'] ?? []);
            $result->warnings = array_merge($result->warnings, $repair['warnings'] ?? []);

            $this->apply($project, $repair['actions'], $result);
            $errors = $this->validate($project, array_keys($result->components));
        }

        $result->errors = $errors;
        $this->recordVersions($project, array_keys($result->components));

        return $result;
    }

    private function apply(Project $project, array $actions, GenerationResult $result): void
    {
        foreach ($actions as $action) {
            try {
                $this->preview->writeComponent($project, $action['component'], $action['php'], $action['blade']);
                $result->recordAction($action);
            } catch (InvalidArgumentException $e) {
                $result->warnings[] = $e->getMessage();
            }
        }
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function validate(Project $project, array $names): array
    {
        $errors = [];
        foreach ($names as $name) {
            array_push($errors, ...$this->preview->validateComponent($project, $name));
        }

        return $errors;
    }

    /**
     * Existing components with their current source: the container has the user's
     * latest edits; project metadata is the fallback when no container is running.
     */
    private function componentContext(Project $project, bool $fromContainer): array
    {
        return collect($project->getComponents())
            ->filter(fn ($component) => preg_match(DockerPreviewService::COMPONENT_NAME_PATTERN, $component['name']))
            ->map(function ($component) use ($project, $fromContainer) {
                $source = $fromContainer
                    ? $this->preview->readComponent($project, $component['name'])
                    : ['php' => $component['code'] ?? '', 'blade' => $component['view_content'] ?? ''];

                return [
                    'name' => $component['name'],
                    'route' => $component['route'],
                    'php' => $source['php'],
                    'blade' => $source['blade'],
                ];
            })
            ->values()
            ->all();
    }

    private function recordVersions(Project $project, array $names): void
    {
        foreach ($names as $name) {
            $source = $this->preview->readComponent($project, $name);
            $kebab = DockerPreviewService::kebab($name);
            $project->addComponent($name, "/{$kebab}", $kebab, $source['php'], $source['blade']);
        }
    }
}
