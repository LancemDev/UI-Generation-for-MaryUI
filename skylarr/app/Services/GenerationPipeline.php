<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Handles one chat message end to end:
 *
 *   1. one AI call with the project's real component source as context
 *   2. write any created/updated components into the preview container and
 *      render them, in a single `docker exec`
 *   3. send failures back to the model, up to max_repair_attempts
 *
 * Used by the ProcessChatMessage job and the skylarr:eval command. Every stage
 * is timed into GenerationResult::$timings.
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
     * @param string|null $selection Part of $focusComponent the change should be limited to
     * @param callable(string): void|null $onReply Receives the reply text as it streams in
     */
    public function run(
        Project $project,
        string $message,
        array $history = [],
        ?string $focusComponent = null,
        ?callable $onStage = null,
        ?string $selection = null,
        ?callable $onReply = null,
    ): GenerationResult {
        $startedAt = microtime(true);
        $stage = $onStage ?? fn (string $stage) => null;
        $stage('thinking');

        $hasContainer = $project->container_id && $this->preview->isContainerRunning($project->container_id);
        $request = [
            'message' => $message,
            'history' => $history,
            'components' => $this->componentContext($project, $hasContainer),
            'focus_component' => $focusComponent,
            'selection' => $selection,
        ];

        $result = new GenerationResult('');
        $firstReplyAt = null;
        $streamReply = $onReply ? function (string $text) use ($onReply, &$firstReplyAt) {
            $firstReplyAt ??= microtime(true);
            $onReply($text);
        } : null;
        $response = $result->time('ai', fn () => $this->ai->generate($request, $streamReply));
        if ($firstReplyAt) {
            $result->addTiming('first_reply', ($firstReplyAt - $startedAt) * 1000);
        }

        $result->reply = $response['reply'];
        $result->model = $response['model'] ?? '';
        $this->recordAiResponse($result, $response);

        if (!empty($response['actions'])) {
            $stage('building');
            $result->previewUrl = $result->time('container', fn () => $this->preview->getOrCreateProjectContainer($project));
            $project->refresh();

            $written = [];
            $errors = $this->applyAndCheck($project, $response['actions'], $result, $written);

            $maxAttempts = config('skylarr.ai.max_repair_attempts');
            while ($errors && $result->repairAttempts < $maxAttempts) {
                $result->repairAttempts++;
                $stage('fixing');
                Log::info('[PIPELINE] Repairing components', ['project_id' => $project->id, 'errors' => $errors]);

                $repair = $result->time('ai', fn () => $this->ai->generate([
                    ...$request,
                    'components' => $this->componentContext($project, true),
                    'selection' => null,
                    'errors' => $errors,
                ]));
                $this->recordAiResponse($result, $repair);

                $errors = $this->applyAndCheck($project, $repair['actions'], $result, $written);
            }

            $result->errors = $errors;
            $this->recordVersions($project, $written);
        }

        $result->addTiming('total', (microtime(true) - $startedAt) * 1000);

        return $result;
    }

    /**
     * Put ready-made components (a template, a restored version) into the preview
     * without an AI call.
     *
     * @param array<string, array{php: string, blade: string, summary: string}> $components
     */
    public function insertComponents(Project $project, array $components, string $reply, ?callable $onStage = null): GenerationResult
    {
        $startedAt = microtime(true);
        ($onStage ?? fn (string $stage) => null)('building');

        $result = new GenerationResult($reply);
        $result->previewUrl = $result->time('container', fn () => $this->preview->getOrCreateProjectContainer($project));
        $project->refresh();

        $existing = array_column($project->getComponents(), 'name');
        $actions = [];
        foreach ($components as $name => $files) {
            $actions[] = ['op' => in_array($name, $existing, true) ? 'update' : 'create', 'component' => $name, ...$files];
        }

        $written = [];
        $result->errors = $this->applyAndCheck($project, $actions, $result, $written);
        $this->recordVersions($project, $written);
        $result->addTiming('total', (microtime(true) - $startedAt) * 1000);

        return $result;
    }

    private function recordAiResponse(GenerationResult $result, array $response): void
    {
        $result->addUsage($response['usage'] ?? []);
        $result->warnings = array_merge($result->warnings, $response['warnings'] ?? []);
        $result->aiCalls += $response['calls'] ?? 1;
        $result->addTiming('model', $response['timings']['model_ms'] ?? 0);
    }

    /**
     * Write the actions' files and render the components they touched, in one exec.
     *
     * @param array<string, array{php: string, blade: string}> $written Latest files per component, updated in place
     * @return list<string> Render errors
     */
    private function applyAndCheck(Project $project, array $actions, GenerationResult $result, array &$written): array
    {
        $files = [];
        foreach ($actions as $action) {
            try {
                DockerPreviewService::assertComponentName($action['component']);
            } catch (InvalidArgumentException $e) {
                $result->warnings[] = $e->getMessage();
                continue;
            }
            $files[$action['component']] = ['php' => $action['php'], 'blade' => $action['blade']];
            $result->recordAction($action);
        }

        $written = array_merge($written, $files);
        if (!$result->components) {
            return [];
        }

        // Re-check everything changed so far: a fix to one component can break another that uses it.
        return $result->time('write_check', fn () => $this->preview->applyComponents($project, $files, array_keys($result->components)));
    }

    /**
     * Existing components with their current source: the container has the user's
     * latest edits; project metadata is the fallback when no container is running.
     */
    private function componentContext(Project $project, bool $fromContainer): array
    {
        $components = array_values(array_filter(
            $project->getComponents(),
            fn ($component) => preg_match(DockerPreviewService::COMPONENT_NAME_PATTERN, $component['name'])
        ));

        $sources = $fromContainer ? $this->preview->readComponents($project, array_column($components, 'name')) : [];

        return array_map(fn ($component) => [
            'name' => $component['name'],
            'route' => $component['route'],
            'php' => $sources[$component['name']]['php'] ?? $component['code'] ?? '',
            'blade' => $sources[$component['name']]['blade'] ?? $component['view_content'] ?? '',
        ], $components);
    }

    /**
     * @param array<string, array{php: string, blade: string}> $written
     */
    private function recordVersions(Project $project, array $written): void
    {
        foreach ($written as $name => $files) {
            $kebab = DockerPreviewService::kebab($name);
            $project->addComponent($name, "/{$kebab}", $kebab, $files['php'], $files['blade']);
        }
    }
}
