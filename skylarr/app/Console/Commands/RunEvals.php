<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Services\DockerPreviewService;
use App\Services\GenerationPipeline;
use App\Services\GenerationResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs prompts from evals/cases.json through the real pipeline (AI service +
 * preview containers) and scores the results, so prompt and model changes can
 * be compared run over run.
 */
class RunEvals extends Command
{
    protected $signature = 'skylarr:eval
        {--cases=evals/cases.json : Path to the cases file, relative to the app root}
        {--case=* : Only run cases with these ids}
        {--keep : Keep eval projects and containers for inspection}';

    protected $description = 'Score generation quality against a fixed set of prompts';

    public function handle(GenerationPipeline $pipeline, DockerPreviewService $preview): int
    {
        $cases = json_decode(file_get_contents(base_path($this->option('cases'))), true, flags: JSON_THROW_ON_ERROR);
        if ($only = $this->option('case')) {
            $cases = array_values(array_filter($cases, fn ($case) => in_array($case['id'], $only, true)));
        }

        // Kept projects hold on to their containers, so the per-user limit would block later cases.
        config(['skylarr.preview.max_containers_per_user' => PHP_INT_MAX]);

        $user = User::firstOrCreate(
            ['email' => 'evals@skylarr.local'],
            ['name' => 'Skylarr Evals', 'password' => Str::random(40)]
        );

        $results = [];
        foreach ($cases as $case) {
            $this->line("<info>▶</info> {$case['id']}");
            $results[] = $result = $this->runCase($case, $user, $pipeline, $preview);
            $this->line($result['passed'] ? '  <fg=green>pass</>' : '  <fg=red>fail</>: ' . implode('; ', $result['failures']));
        }

        $this->summarize($results);

        return collect($results)->every('passed') ? self::SUCCESS : self::FAILURE;
    }

    private function runCase(array $case, User $user, GenerationPipeline $pipeline, DockerPreviewService $preview): array
    {
        $project = Project::create([
            'user_id' => $user->id,
            'name' => "eval: {$case['id']}",
            'status' => 'creating',
            'metadata' => ['components' => []],
        ]);

        $startedAt = microtime(true);
        $history = [];
        $result = null;
        $renderErrors = [];
        $repairs = 0;
        $usage = [];
        $timings = [];
        $aiCalls = 0;
        $renderAttempted = false;

        try {
            foreach ($case['turns'] as $turn) {
                $result = $pipeline->run($project, $turn, $history);
                $project->refresh();
                $history[] = ['role' => 'user', 'content' => $turn];
                $history[] = ['role' => 'assistant', 'content' => $result->reply];
                $renderErrors = array_merge($renderErrors, $result->errors);
                $renderAttempted = $renderAttempted || $result->hasChanges() || $result->errors !== [];
                $repairs += $result->repairAttempts;
                foreach ($result->usage as $key => $value) {
                    $usage[$key] = ($usage[$key] ?? 0) + $value;
                }
                foreach ($result->timings as $stage => $ms) {
                    $timings[$stage] = ($timings[$stage] ?? 0) + $ms;
                }
                $aiCalls += $result->aiCalls;
            }
            $failures = $this->check($case['expect'] ?? [], $result, $project, $preview);
        } catch (Throwable $e) {
            $failures = ['exception: ' . $e->getMessage()];
        } finally {
            if (!$this->option('keep')) {
                if ($project->container_id) {
                    $preview->removeContainer($project->container_id);
                }
                $project->delete();
            }
        }

        if ($renderErrors) {
            $failures[] = 'render errors: ' . implode(' | ', $renderErrors);
        }

        return [
            'id' => $case['id'],
            'passed' => $failures === [],
            'failures' => $failures,
            // null for question-only cases, which never render anything.
            'rendered' => $renderAttempted
                ? $renderErrors === [] && !collect($failures)->contains(fn ($f) => str_starts_with($f, 'exception'))
                : null,
            'repair_attempts' => $repairs,
            'components' => $result ? array_keys($result->components) : [],
            'seconds' => round(microtime(true) - $startedAt, 1),
            'usage' => $usage,
            'timings' => $timings,
            'ai_calls' => $aiCalls,
            'model' => $result?->model,
        ];
    }

    /**
     * @return list<string>
     */
    private function check(array $expect, GenerationResult $result, Project $project, DockerPreviewService $preview): array
    {
        $failures = [];
        $changed = count($result->components);
        $total = count($project->getComponents());

        $bounds = [
            'changed_min' => fn ($v) => $changed >= $v ?: "changed {$changed} components, expected at least {$v}",
            'changed_max' => fn ($v) => $changed <= $v ?: "changed {$changed} components, expected at most {$v}",
            'project_min' => fn ($v) => $total >= $v ?: "project has {$total} components, expected at least {$v}",
            'project_max' => fn ($v) => $total <= $v ?: "project has {$total} components, expected at most {$v}",
        ];
        foreach ($bounds as $key => $assert) {
            if (isset($expect[$key]) && ($outcome = $assert($expect[$key])) !== true) {
                $failures[] = $outcome;
            }
        }

        if (isset($expect['final_op'])) {
            $ops = array_unique(array_column($result->components, 'op'));
            if ($ops !== [$expect['final_op']]) {
                $failures[] = 'final turn ops were [' . implode(',', $ops) . "], expected {$expect['final_op']}";
            }
        }

        $blade = '';
        foreach (array_keys($result->components) as $name) {
            $blade .= $preview->readComponent($project, $name)['blade'];
        }
        $blade = strtolower($blade);

        foreach ($expect['blade_contains'] ?? [] as $needle) {
            if (!str_contains($blade, strtolower($needle))) {
                $failures[] = "views do not contain {$needle}";
            }
        }
        foreach ($expect['blade_not_contains'] ?? [] as $needle) {
            if (str_contains($blade, strtolower($needle))) {
                $failures[] = "views contain {$needle}";
            }
        }

        return $failures;
    }

    private function summarize(array $results): void
    {
        $count = max(count($results), 1);
        $renderable = collect($results)->whereNotNull('rendered');
        $summary = [
            'pass_rate' => round(collect($results)->where('passed', true)->count() / $count, 3),
            'render_rate' => round($renderable->where('rendered', true)->count() / max($renderable->count(), 1), 3),
            'avg_repair_attempts' => round(collect($results)->avg('repair_attempts') ?? 0, 2),
            'avg_seconds' => round(collect($results)->avg('seconds') ?? 0, 1),
            'input_tokens' => $input = collect($results)->sum(fn ($r) => $r['usage']['input_tokens'] ?? 0),
            'cached_input_tokens' => $cached = collect($results)->sum(fn ($r) => $r['usage']['cached_input_tokens'] ?? 0),
            'cache_hit_rate' => round($cached / max($input, 1), 3),
            'output_tokens' => collect($results)->sum(fn ($r) => $r['usage']['output_tokens'] ?? 0),
            'avg_ai_calls' => round(collect($results)->avg('ai_calls') ?? 0, 2),
        ];

        // Average milliseconds per stage, to see where time goes before optimizing.
        foreach (['ai', 'model', 'first_reply', 'container', 'write_check', 'total'] as $stage) {
            $values = collect($results)->pluck("timings.{$stage}")->filter(fn ($v) => $v !== null);
            if ($values->isNotEmpty()) {
                $summary["avg_{$stage}_ms"] = (int) round($values->avg());
            }
        }

        $this->newLine();
        $this->table(['metric', 'value'], collect($summary)->map(fn ($v, $k) => [$k, $v])->values());

        $path = 'evals/' . now()->format('Y-m-d_His') . '.json';
        Storage::put($path, json_encode(['summary' => $summary, 'cases' => $results], JSON_PRETTY_PRINT));
        $this->info('Report written to ' . Storage::path($path));
    }
}
