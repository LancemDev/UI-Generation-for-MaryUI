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

    public function test_files_are_written_through_stdin_as_www_data(): void
    {
        Process::fake();

        app(DockerPreviewService::class)->writeComponent($this->project(), 'UserTable', '<?php // "quotes" $vars', '<div>{{ $x }}</div>');

        Process::assertRanTimes(function (PendingProcess $process) {
            $command = $process->command;

            return $command[0] === 'docker'
                && array_slice($command, 1, 6) === ['exec', '-u', 'www-data', '-w', '/var/www/html', '-i']
                && end($command) === '/var/www/html/app/Livewire/UserTable.php'
                && $process->input === '<?php // "quotes" $vars';
        }, 1);
        Process::assertRan(fn (PendingProcess $process) => end($process->command) === '/var/www/html/resources/views/livewire/user-table.blade.php');
    }

    public function test_validate_reports_syntax_errors(): void
    {
        Process::fake([
            '*php*-l*' => Process::result(output: "PHP Parse error: syntax error, unexpected '}' in app/Livewire/UserTable.php on line 9\nErrors parsing app/Livewire/UserTable.php", exitCode: 255),
        ]);

        $errors = app(DockerPreviewService::class)->validateComponent($this->project(), 'UserTable');

        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('UserTable: PHP syntax error', $errors[0]);
        $this->assertStringContainsString("unexpected '}'", $errors[0]);
    }

    public function test_validate_extracts_the_render_error_from_the_log(): void
    {
        Process::fake([
            '*curl*' => Process::result(output: '500'),
            '*tail*' => Process::result(output: "[2026-10-11 12:00:00] preview.ERROR: Undefined variable \$users (View: /var/www/html/resources/views/livewire/user-table.blade.php) {\"exception\":\"...\"}\n"),
            '*' => Process::result(),
        ]);

        $errors = app(DockerPreviewService::class)->validateComponent($this->project(), 'UserTable');

        $this->assertSame(
            ['UserTable: rendering /user-table failed (HTTP 500): Undefined variable $users (View: /var/www/html/resources/views/livewire/user-table.blade.php)'],
            $errors
        );
    }

    public function test_validate_passes_when_the_route_renders(): void
    {
        Process::fake(['*curl*' => Process::result(output: '200'), '*' => Process::result()]);

        $this->assertSame([], app(DockerPreviewService::class)->validateComponent($this->project(), 'UserTable'));
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
