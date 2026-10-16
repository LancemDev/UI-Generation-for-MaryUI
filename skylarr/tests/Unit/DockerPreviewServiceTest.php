<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\DockerPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Tests\TestCase;

class DockerPreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return new Project(['container_id' => 'abc123', 'metadata' => ['components' => []]]);
    }

    public function test_component_paths_and_kebab_case(): void
    {
        $this->assertSame([
            'php' => 'app/Livewire/UserTable.php',
            'blade' => 'resources/views/livewire/user-table.blade.php',
        ], DockerPreviewService::componentPaths('UserTable'));
    }

    public function test_rejects_component_names_that_could_escape_paths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DockerPreviewService::componentPaths('../../etc/passwd');
    }

    public function test_only_livewire_files_are_editable(): void
    {
        $this->assertSame('app/Livewire/UserTable.php', DockerPreviewService::editablePath('/var/www/html/app/Livewire/UserTable.php'));
        $this->assertSame('resources/views/livewire/user-table.blade.php', DockerPreviewService::editablePath('/var/www/html/resources/views/livewire/user-table.blade.php'));
        $this->assertNull(DockerPreviewService::editablePath('/var/www/html/routes/web.php'));
        $this->assertNull(DockerPreviewService::editablePath('/var/www/html/app/Livewire/../../.env.php'));
    }

    private function batchResult(array $output): \Illuminate\Process\FakeProcessResult
    {
        return Process::result(output: json_encode($output + ['written' => [], 'read' => [], 'checks' => []]));
    }

    public function test_components_are_written_and_checked_in_one_exec_as_www_data(): void
    {
        Process::fake(['*' => $this->batchResult([
            'written' => ['app/Livewire/UserTable.php' => true, 'resources/views/livewire/user-table.blade.php' => true],
            'checks' => ['UserTable' => ['status' => '200', 'log' => '']],
        ])]);

        $errors = app(DockerPreviewService::class)->applyComponents($this->project(), [
            'UserTable' => ['php' => '<?php // "quotes" $vars', 'blade' => '<div>{{ $x }}</div>'],
        ]);

        $this->assertSame([], $errors);
        Process::assertRanTimes(function (PendingProcess $process) {
            $command = $process->command;
            $payload = json_decode($process->input, true);

            return array_slice($command, 0, 7) === ['docker', 'exec', '-u', 'www-data', '-w', '/var/www/html', '-i']
                && array_slice($command, 8, 2) === ['php', '-r']
                // Content travels as JSON on stdin, never through a shell.
                && $payload['write'] === [
                    'app/Livewire/UserTable.php' => '<?php // "quotes" $vars',
                    'resources/views/livewire/user-table.blade.php' => '<div>{{ $x }}</div>',
                ]
                && $payload['check'] === ['UserTable' => ['php' => 'app/Livewire/UserTable.php', 'route' => '/user-table']];
        }, 1);
    }

    public function test_validate_reports_syntax_errors(): void
    {
        Process::fake(['*' => $this->batchResult(['checks' => ['UserTable' => [
            'lint' => "PHP Parse error: syntax error, unexpected '}' in app/Livewire/UserTable.php on line 9\nErrors parsing app/Livewire/UserTable.php",
        ]]])]);

        $errors = app(DockerPreviewService::class)->validateComponent($this->project(), 'UserTable');

        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('UserTable: PHP syntax error', $errors[0]);
        $this->assertStringContainsString("unexpected '}'", $errors[0]);
        $this->assertStringNotContainsString('Errors parsing', $errors[0]);
    }

    public function test_validate_extracts_the_render_error_from_the_log(): void
    {
        Process::fake(['*' => $this->batchResult(['checks' => ['UserTable' => [
            'status' => '500',
            'log' => "[2026-10-11 12:00:00] preview.ERROR: Undefined variable \$users (View: /var/www/html/resources/views/livewire/user-table.blade.php) {\"exception\":\"...\"}\n",
        ]]])]);

        $errors = app(DockerPreviewService::class)->validateComponent($this->project(), 'UserTable');

        $this->assertSame(
            ['UserTable: rendering /user-table failed (HTTP 500): Undefined variable $users (View: /var/www/html/resources/views/livewire/user-table.blade.php)'],
            $errors
        );
    }

    public function test_validate_passes_when_the_route_renders(): void
    {
        Process::fake(['*' => $this->batchResult(['checks' => ['UserTable' => ['status' => '200', 'log' => '']]])]);

        $this->assertSame([], app(DockerPreviewService::class)->validateComponent($this->project(), 'UserTable'));
    }

    public function test_component_sources_are_read_in_one_exec(): void
    {
        Process::fake(['*' => $this->batchResult(['read' => [
            'app/Livewire/UserTable.php' => '<?php table',
            'resources/views/livewire/user-table.blade.php' => '<div>table</div>',
            'app/Livewire/SignupForm.php' => '<?php form',
        ]])]);

        $sources = app(DockerPreviewService::class)->readComponents($this->project(), ['UserTable', 'SignupForm']);

        $this->assertSame([
            'UserTable' => ['php' => '<?php table', 'blade' => '<div>table</div>'],
            'SignupForm' => ['php' => '<?php form', 'blade' => ''],
        ], $sources);
        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_a_paused_container_is_resumed_and_the_command_retried(): void
    {
        $calls = 0;
        Process::fake(function (PendingProcess $process) use (&$calls) {
            if (($process->command[1] ?? null) === 'exec' && $calls++ === 0) {
                return Process::result(errorOutput: 'Error response from daemon: container abc123 is paused, unpause the container before exec', exitCode: 1);
            }

            return $this->batchResult(['checks' => ['UserTable' => ['status' => '200', 'log' => '']]]);
        });

        $this->assertSame([], app(DockerPreviewService::class)->validateComponent($this->project(), 'UserTable'));
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'unpause', 'abc123']);
    }

    public function test_only_idle_projects_are_paused(): void
    {
        config(['skylarr.preview.pause_after_minutes' => 15]);
        $user = \App\Models\User::factory()->create();
        $make = fn (string $container, int $minutesAgo, array $metadata = []) => Project::create([
            'user_id' => $user->id, 'name' => $container, 'status' => 'active', 'container_id' => $container,
            'last_accessed_at' => now()->subMinutes($minutesAgo), 'metadata' => ['components' => [], ...$metadata],
        ]);
        $make('idle', 30);
        $make('recent', 2);
        $make('busy', 30, ['generation' => ['is_generating' => true]]);

        Process::fake([
            '*inspect*' => Process::result(output: 'true false'),
            '*' => Process::result(),
        ]);

        $this->assertSame(1, app(DockerPreviewService::class)->pauseIdleContainers());
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'pause', 'idle']);
        Process::assertNotRan(fn (PendingProcess $process) => in_array($process->command, [['docker', 'pause', 'recent'], ['docker', 'pause', 'busy']], true));
    }

    public function test_containers_are_started_with_resource_limits_and_dropped_capabilities(): void
    {
        config(['skylarr.preview.pool_size' => 0]);
        Process::fake([
            '*docker run*' => Process::result(output: "container123\n"),
            '*curl*' => Process::result(output: '200'),
            '*' => Process::result(),
        ]);

        app(DockerPreviewService::class)->fillPool();
        config(['skylarr.preview.pool_size' => 1]);
        app(DockerPreviewService::class)->fillPool();

        Process::assertRan(function (PendingProcess $process) {
            $command = implode(' ', $process->command);

            return str_contains($command, 'docker run -d')
                && str_contains($command, '--memory 512m')
                && str_contains($command, '--pids-limit 256')
                && str_contains($command, '--cap-drop ALL')
                && str_contains($command, '--security-opt no-new-privileges')
                && preg_match('/-p 127\.0\.0\.1:\d+:80/', $command);
        });
    }

    public function test_theme_update_keeps_the_layout_valid(): void
    {
        $layout = file_get_contents(base_path('../preview-reference/resources/views/components/layouts/app.blade.php'));
        Process::fake([
            '*cat*/layouts/app.blade.php*' => Process::result(output: $layout),
            '*' => Process::result(),
        ]);

        app(DockerPreviewService::class)->updatePreviewTheme('abc123', 'dracula');

        Process::assertRan(function (PendingProcess $process) {
            return is_string($process->input)
                && str_contains($process->input, '<html lang="{{ str_replace(\'_\', \'-\', app()->getLocale()) }}" data-theme="dracula">')
                && substr_count($process->input, 'data-theme=') === 1;
        });
    }
}
