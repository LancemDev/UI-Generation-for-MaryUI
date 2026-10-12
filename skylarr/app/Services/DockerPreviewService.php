<?php

namespace App\Services;

use App\Jobs\FillPreviewPool;
use App\Models\Project;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Runs each project's preview in its own hardened container built from
 * docker/Dockerfile.preview, and reads/writes component files inside it.
 *
 * The preview image auto-registers a route for every class in app/Livewire and
 * runs without route/config caches or opcache, so writing a component's two
 * files is all it takes for it to be served at /<kebab-name>.
 */
class DockerPreviewService
{
    public const APP_ROOT = '/var/www/html';
    public const COMPONENT_NAME_PATTERN = '/^[A-Z][A-Za-z0-9]{1,63}$/';

    private const PROJECT_PREFIX = 'skylarr-project-';
    private const POOL_PREFIX = 'skylarr-pool-';
    private const LEGACY_PREFIX = 'skylarr-';
    private const ROLE_LABEL = 'skylarr.role=preview';
    private const EDITABLE_PATH_PATTERN = '#^(app/Livewire|resources/views/livewire)/[A-Za-z0-9_\-/]+(\.blade)?\.php$#';

    /**
     * Runs inside the container (php -r) so a whole batch of writes, reads and
     * render checks costs one `docker exec` instead of several per component.
     * Input arrives as JSON on stdin; paths are validated before they get here.
     * Renders run one at a time so each failure is matched to its own log lines.
     */
    private const BATCH_SCRIPT = <<<'PHP'
$in = json_decode(stream_get_contents(STDIN), true);
$out = ['written' => [], 'read' => [], 'checks' => []];
foreach ($in['write'] ?? [] as $path => $content) {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $out['written'][$path] = file_put_contents($path, $content) !== false;
}
foreach ($in['read'] ?? [] as $path) {
    $out['read'][$path] = is_file($path) ? file_get_contents($path) : '';
}
$log = 'storage/logs/laravel.log';
foreach ($in['check'] ?? [] as $name => $check) {
    $lint = [];
    exec('php -l ' . escapeshellarg($check['php']) . ' 2>&1', $lint, $code);
    if ($code !== 0) {
        $out['checks'][$name] = ['lint' => implode("\n", $lint)];
        continue;
    }
    clearstatcache(true, $log);
    $offset = is_file($log) ? filesize($log) : 0;
    $url = escapeshellarg('http://127.0.0.1' . $check['route']);
    $status = trim((string) shell_exec("curl -s -o /dev/null -w '%{http_code}' --max-time 20 {$url}"));
    clearstatcache(true, $log);
    $size = is_file($log) ? filesize($log) : 0;
    $tail = $size > $offset ? file_get_contents($log, false, null, $offset, 20000) : '';
    $out['checks'][$name] = ['status' => $status, 'log' => $tail];
}
echo json_encode($out);
PHP;

    // ---------------------------------------------------------------------
    // Container lifecycle
    // ---------------------------------------------------------------------

    /**
     * Return the base preview URL for a project, starting a container if needed.
     */
    public function getOrCreateProjectContainer(Project $project): string
    {
        if ($project->container_id && $this->isContainerRunning($project->container_id)) {
            $this->wake($project);

            return $this->baseUrl($project->port);
        }

        if ($project->container_id) {
            $this->removeContainer($project->container_id);
        }

        return $this->createProjectContainer($project);
    }

    public function createProjectContainer(Project $project): string
    {
        if (!$this->canUserCreateContainer($project->user_id, $project->id)) {
            throw new RuntimeException('You have reached the maximum number of running previews. Stop one and try again.');
        }

        $name = self::PROJECT_PREFIX . "{$project->id}-" . Str::lower(Str::random(6));
        $container = $this->claimPooledContainer($name) ?? $this->startContainer($name);

        $project->update([
            'container_id' => $container['id'],
            'container_name' => $name,
            'port' => $container['port'],
            'preview_url' => $this->baseUrl($container['port']),
            'status' => 'active',
            'last_accessed_at' => now(),
        ]);

        $theme = $project->metadata['selected_theme'] ?? null;
        if ($theme) {
            $this->updatePreviewTheme($container['id'], $theme);
        }

        $this->restoreComponents($project);

        Log::info('[PREVIEW] Container ready', ['project_id' => $project->id, 'port' => $container['port']]);

        return $this->baseUrl($container['port']);
    }

    /**
     * Start idle containers until the pool is full. Returns how many were started.
     */
    public function fillPool(): int
    {
        $missing = config('skylarr.preview.pool_size') - count($this->poolContainers());
        $started = 0;

        for ($i = 0; $i < $missing; $i++) {
            $this->startContainer(self::POOL_PREFIX . Str::lower(Str::random(8)));
            $started++;
        }

        return $started;
    }

    /**
     * Running includes paused: a paused container resumes instantly with its state intact.
     */
    public function isContainerRunning(string $containerId): bool
    {
        return $this->containerState($containerId)['running'];
    }

    /**
     * @return array{running: bool, paused: bool}
     */
    public function containerState(string $containerId): array
    {
        $result = $this->docker(['inspect', '-f', '{{.State.Running}} {{.State.Paused}}', $containerId]);
        [$running, $paused] = array_pad(explode(' ', trim($result->output())), 2, 'false');

        return ['running' => $result->successful() && $running === 'true', 'paused' => $paused === 'true'];
    }

    /**
     * Resume a project's container if it was paused for inactivity, and mark it as in use.
     */
    public function wake(Project $project): void
    {
        if ($project->container_id && $this->containerState($project->container_id)['paused']) {
            $this->docker(['unpause', $project->container_id]);
        }
        $project->touchLastAccessed();
    }

    /**
     * Pause containers of projects nobody has used for `pause_after_minutes`. A paused
     * container uses no CPU and resumes in milliseconds; memory stays allocated until
     * `cleanupExpiredContainers` removes it after `idle_hours`.
     */
    public function pauseIdleContainers(): int
    {
        $minutes = config('skylarr.preview.pause_after_minutes');
        if ($minutes <= 0) {
            return 0;
        }

        $paused = 0;
        Project::whereNotNull('container_id')
            ->where('last_accessed_at', '<', now()->subMinutes($minutes))
            ->each(function (Project $project) use (&$paused) {
                $state = $this->containerState($project->container_id);
                if ($project->isGenerating() || !$state['running'] || $state['paused']) {
                    return;
                }
                if ($this->docker(['pause', $project->container_id])->successful()) {
                    $paused++;
                }
            });

        return $paused;
    }

    /**
     * Live CPU and memory use of every Skylarr container, for tuning PREVIEW_MEMORY.
     *
     * @return list<array{name: string, cpu: string, memory: string, memory_percent: string}>
     */
    public function containerStats(): array
    {
        $result = $this->docker([
            'stats', '--no-stream', '--format', '{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}',
        ], timeout: 30);

        return collect(explode("\n", trim($result->output())))
            ->filter(fn ($line) => str_starts_with($line, self::LEGACY_PREFIX))
            ->map(function ($line) {
                [$name, $cpu, $memory, $percent] = array_pad(explode("\t", $line), 4, '');

                return ['name' => $name, 'cpu' => $cpu, 'memory' => $memory, 'memory_percent' => $percent];
            })
            ->values()
            ->all();
    }

    public function removeContainer(string $containerId): bool
    {
        $result = $this->docker(['rm', '-f', $containerId]);

        if ($result->failed()) {
            Log::warning('[PREVIEW] Failed to remove container', ['container_id' => $containerId, 'error' => $result->errorOutput()]);
        }

        return $result->successful();
    }

    public function canUserCreateContainer(int $userId, ?int $exceptProjectId = null): bool
    {
        $running = Project::where('user_id', $userId)
            ->whereNotNull('container_id')
            ->when($exceptProjectId, fn ($query) => $query->where('id', '!=', $exceptProjectId))
            ->count();

        return $running < config('skylarr.preview.max_containers_per_user');
    }

    /**
     * Containers belonging to a user's projects, with their live status.
     */
    public function getUserContainers(int $userId): array
    {
        return Project::where('user_id', $userId)
            ->whereNotNull('container_id')
            ->get()
            ->map(fn (Project $project) => [
                'id' => Str::limit($project->container_id, 12, ''),
                'name' => $project->container_name,
                'project_id' => $project->id,
                'port' => $project->port,
                'running' => $this->isContainerRunning($project->container_id),
            ])
            ->all();
    }

    /**
     * Stop containers of projects nobody has opened for `idle_hours`.
     */
    public function cleanupExpiredContainers(): int
    {
        $cutoff = now()->subHours(config('skylarr.preview.idle_hours'));
        $cleaned = 0;

        Project::whereNotNull('container_id')
            ->where(fn ($query) => $query->where('last_accessed_at', '<', $cutoff)->orWhereNull('last_accessed_at'))
            ->each(function (Project $project) use (&$cleaned) {
                $this->removeContainer($project->container_id);
                $this->detach($project);
                $cleaned++;
            });

        return $cleaned;
    }

    /**
     * Remove Skylarr containers that no project points to (pool containers excepted).
     */
    public function cleanupOrphanedContainers(): int
    {
        $cleaned = 0;

        foreach ($this->listContainers(all: true) as $container) {
            if (str_starts_with($container['name'], self::POOL_PREFIX)) {
                continue;
            }

            $owned = Project::where('container_id', $container['id'])
                ->orWhere('container_name', $container['name'])
                ->exists();

            if (!$owned && $this->removeContainer($container['id'])) {
                $cleaned++;
            }
        }

        return $cleaned;
    }

    public function findContainerByPort(int $port): ?array
    {
        foreach ($this->listContainers(all: false) as $container) {
            if (in_array($port, $container['ports'], true)) {
                return $container;
            }
        }

        return null;
    }

    public function removeContainerByPort(int $port): bool
    {
        $container = $this->findContainerByPort($port);

        return $container ? $this->removeContainer($container['id']) : false;
    }

    public function removeAllSkylarrContainers(): int
    {
        $removed = 0;
        foreach ($this->listContainers(all: true) as $container) {
            if ($this->removeContainer($container['id'])) {
                $removed++;
            }
        }

        Project::whereNotNull('container_id')->each(fn (Project $project) => $this->detach($project));

        return $removed;
    }

    public function detach(Project $project): void
    {
        $project->update([
            'container_id' => null,
            'container_name' => null,
            'port' => null,
            'preview_url' => null,
            'status' => 'stopped',
        ]);
    }

    // ---------------------------------------------------------------------
    // Component files
    // ---------------------------------------------------------------------

    public static function assertComponentName(string $name): void
    {
        if (!preg_match(self::COMPONENT_NAME_PATTERN, $name)) {
            throw new InvalidArgumentException("Invalid component name: {$name}");
        }
    }

    public static function kebab(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }

    public static function componentPaths(string $name): array
    {
        self::assertComponentName($name);

        return [
            'php' => "app/Livewire/{$name}.php",
            'blade' => 'resources/views/livewire/' . self::kebab($name) . '.blade.php',
        ];
    }

    public function writeComponent(Project $project, string $name, string $php, string $blade): void
    {
        $this->applyComponents($project, [$name => ['php' => $php, 'blade' => $blade]], validate: []);
    }

    /**
     * Write components and render-check some of them, all in one `docker exec`.
     *
     * @param array<string, array{php: string, blade: string}> $components
     * @param list<string>|null $validate Names to check; null checks every written component
     * @return list<string> Human-readable errors; empty when everything renders
     */
    public function applyComponents(Project $project, array $components, ?array $validate = null): array
    {
        $write = [];
        foreach ($components as $name => $files) {
            $paths = self::componentPaths($name);
            $write[$paths['php']] = $files['php'];
            $write[$paths['blade']] = $files['blade'];
        }

        $check = [];
        foreach ($validate ?? array_keys($components) as $name) {
            $check[$name] = ['php' => self::componentPaths($name)['php'], 'route' => '/' . self::kebab($name)];
        }

        $output = $this->batch($this->requireContainer($project), ['write' => $write, 'check' => $check]);

        if ($failed = array_keys(array_filter($output['written'] ?? [], fn ($ok) => !$ok))) {
            throw new RuntimeException('Failed to write ' . implode(', ', $failed));
        }

        $errors = [];
        foreach ($output['checks'] ?? [] as $name => $result) {
            if ($error = $this->describeCheck($name, $result)) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * Write every component saved in project metadata into a fresh container.
     */
    public function restoreComponents(Project $project): void
    {
        $components = [];
        foreach ($project->getComponents() as $component) {
            if (empty($component['code']) || !preg_match(self::COMPONENT_NAME_PATTERN, $component['name'])) {
                continue;
            }
            $components[$component['name']] = ['php' => $component['code'], 'blade' => $component['view_content'] ?? '<div></div>'];
        }

        if ($components) {
            $this->applyComponents($project, $components, validate: []);
        }
    }

    /**
     * @return array{php: string, blade: string}
     */
    public function readComponent(Project $project, string $name): array
    {
        return $this->readComponents($project, [$name])[$name];
    }

    /**
     * Current source of several components in one `docker exec`.
     *
     * @param list<string> $names
     * @return array<string, array{php: string, blade: string}>
     */
    public function readComponents(Project $project, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $paths = array_combine($names, array_map(self::componentPaths(...), $names));
        $read = $this->batch($this->requireContainer($project), ['read' => array_merge(...array_map('array_values', array_values($paths)))])['read'] ?? [];

        return array_map(fn ($p) => ['php' => $read[$p['php']] ?? '', 'blade' => $read[$p['blade']] ?? ''], $paths);
    }

    /**
     * Write a user-editable file (a component class or view) inside the container.
     */
    public function writeFile(string $containerId, string $relativePath, string $content): void
    {
        if (str_contains($relativePath, '..') || !preg_match(self::EDITABLE_PATH_PATTERN, $relativePath)) {
            throw new InvalidArgumentException("Only Livewire components and views can be edited: {$relativePath}");
        }

        $this->putFile($containerId, self::APP_ROOT . '/' . $relativePath, $content);
    }

    /**
     * Map an absolute container path to an editable relative path, or null if it isn't editable.
     */
    public static function editablePath(string $absolutePath): ?string
    {
        $relative = Str::after($absolutePath, self::APP_ROOT . '/');

        return !str_contains($relative, '..') && preg_match(self::EDITABLE_PATH_PATTERN, $relative) ? $relative : null;
    }

    /**
     * Run a component the way the browser will and report what breaks.
     *
     * @return list<string> Human-readable errors; empty when the component renders.
     */
    public function validateComponent(Project $project, string $name): array
    {
        return $this->applyComponents($project, [], validate: [$name]);
    }

    public function listProjectFiles(string $containerId): array
    {
        $result = $this->exec($containerId, [
            'find', 'app/Livewire', 'resources/views/livewire', 'routes', '-type', 'f', '-name', '*.php',
        ]);

        if ($result->failed()) {
            return [];
        }

        return collect(explode("\n", trim($result->output())))
            ->filter()
            ->map(fn ($path) => self::APP_ROOT . '/' . ltrim($path, './'))
            ->sort()
            ->values()
            ->all();
    }

    public function readFileFromContainer(string $containerId, string $filePath): string
    {
        if (!str_starts_with($filePath, self::APP_ROOT . '/') || str_contains($filePath, '..')) {
            return '';
        }

        $result = $this->exec($containerId, ['cat', $filePath]);

        return $result->successful() ? $result->output() : '';
    }

    public function updatePreviewTheme(string $containerId, string $theme): bool
    {
        if (!preg_match('/^[a-z]+$/', $theme)) {
            throw new InvalidArgumentException("Invalid theme: {$theme}");
        }

        $layoutPath = self::APP_ROOT . '/resources/views/components/layouts/app.blade.php';
        $layout = $this->readFileFromContainer($containerId, $layoutPath);
        if ($layout === '') {
            return false;
        }

        // Match the whole <html ...> line up to its last ">": the lang attribute contains
        // app()->getLocale(), so the first ">" is not the end of the tag.
        $updated = preg_replace_callback('/^(\s*<html\b.*)>[ \t]*$/mi', function ($matches) use ($theme) {
            $open = preg_replace('/\s+data-theme="[^"]*"/i', '', $matches[1]);

            return "{$open} data-theme=\"{$theme}\">";
        }, $layout, 1);

        $this->putFile($containerId, $layoutPath, $updated);

        return true;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @return array{id: string, port: int}
     */
    private function startContainer(string $name): array
    {
        $config = config('skylarr.preview');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = $this->nextFreePort();
            $args = [
                'run', '-d',
                '--name', $name,
                '--label', self::ROLE_LABEL,
                '-p', "{$config['bind_host']}:{$port}:80",
                '--memory', $config['memory'],
                '--cpus', (string) $config['cpus'],
                '--pids-limit', (string) $config['pids_limit'],
                '--security-opt', 'no-new-privileges',
                // nginx, php-fpm and supervisord only need to bind :80 and drop to their users.
                '--cap-drop', 'ALL',
                '--cap-add', 'CHOWN', '--cap-add', 'SETUID', '--cap-add', 'SETGID',
                '--cap-add', 'DAC_OVERRIDE', '--cap-add', 'NET_BIND_SERVICE',
            ];
            if ($config['network']) {
                array_push($args, '--network', $config['network']);
            }
            $args[] = $config['image'];

            $result = $this->docker($args, timeout: 120);

            if ($result->successful()) {
                $id = trim($result->output());
                $this->waitUntilReady($id);

                return ['id' => $id, 'port' => $port];
            }

            $this->docker(['rm', '-f', $name]);
            if (!str_contains($result->errorOutput(), 'port is already allocated')) {
                throw new RuntimeException('Failed to start preview container: ' . trim($result->errorOutput()));
            }
        }

        throw new RuntimeException('Failed to start preview container: no free port found');
    }

    /**
     * Take over an idle pool container by renaming it (rename is atomic, so two
     * workers can't claim the same one).
     *
     * @return array{id: string, port: int}|null
     */
    private function claimPooledContainer(string $newName): ?array
    {
        foreach ($this->poolContainers() as $container) {
            if (!$container['ports'] || $this->docker(['rename', $container['name'], $newName])->failed()) {
                continue;
            }

            FillPreviewPool::dispatch();

            return ['id' => $container['id'], 'port' => $container['ports'][0]];
        }

        return null;
    }

    private function poolContainers(): array
    {
        return array_values(array_filter(
            $this->listContainers(all: false),
            fn ($container) => str_starts_with($container['name'], self::POOL_PREFIX)
        ));
    }

    /**
     * @return list<array{id: string, name: string, ports: list<int>}>
     */
    private function listContainers(bool $all): array
    {
        $args = ['ps', '--no-trunc', '--filter', 'name=' . self::LEGACY_PREFIX, '--format', '{{.ID}}\t{{.Names}}\t{{.Ports}}'];
        if ($all) {
            array_splice($args, 1, 0, ['-a']);
        }

        $result = $this->docker($args);
        if ($result->failed()) {
            return [];
        }

        $containers = [];
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            [$id, $name, $ports] = array_pad(explode("\t", $line), 3, '');
            preg_match_all('/:(\d+)->80\/tcp/', $ports, $matches);
            $containers[] = ['id' => $id, 'name' => $name, 'ports' => array_values(array_unique(array_map('intval', $matches[1])))];
        }

        return $containers;
    }

    private function nextFreePort(): int
    {
        $base = config('skylarr.preview.base_port');
        $used = Project::whereNotNull('port')->pluck('port')->all();

        $published = $this->docker(['ps', '--format', '{{.Ports}}'])->output();
        preg_match_all('/:(\d+)->/', $published, $matches);
        $used = array_merge($used, array_map('intval', $matches[1]));

        $listening = Process::run(['sh', '-c', 'ss -ltnH 2>/dev/null || netstat -ltn 2>/dev/null'])->output();
        preg_match_all('/:(\d+)\s/', $listening, $matches);
        $used = array_flip(array_merge($used, array_map('intval', $matches[1])));

        $candidates = range($base, $base + config('skylarr.preview.port_range') - 1);
        shuffle($candidates); // spread concurrent starts across the range

        foreach ($candidates as $port) {
            if (!isset($used[$port])) {
                return $port;
            }
        }

        throw new RuntimeException('No free preview ports left');
    }

    private function waitUntilReady(string $containerId): void
    {
        $deadline = time() + config('skylarr.preview.ready_timeout');

        while (time() < $deadline) {
            $status = $this->exec($containerId, ['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', 'http://127.0.0.1/up'])->output();
            if (trim($status) === '200') {
                return;
            }
            usleep(500_000);
        }

        throw new RuntimeException('Preview container did not become ready in time');
    }

    private function requireContainer(Project $project): string
    {
        if (!$project->container_id) {
            throw new RuntimeException("Project {$project->id} has no preview container");
        }

        return $project->container_id;
    }

    private function baseUrl(?int $port): string
    {
        return 'http://' . config('skylarr.preview.public_host') . ":{$port}";
    }

    private function putFile(string $containerId, string $absolutePath, string $content): void
    {
        // The path is passed as a positional argument, never interpolated into the script.
        $result = $this->exec(
            $containerId,
            ['sh', '-c', 'mkdir -p "$(dirname "$1")" && cat > "$1"', 'sh', $absolutePath],
            input: $content
        );

        if ($result->failed()) {
            throw new RuntimeException("Failed to write {$absolutePath}: " . trim($result->errorOutput()));
        }
    }

    /**
     * @param array{write?: array<string, string>, read?: list<string>, check?: array<string, array{php: string, route: string}>} $payload
     */
    private function batch(string $containerId, array $payload): array
    {
        $timeout = 30 + 25 * count($payload['check'] ?? []);
        $result = $this->exec($containerId, ['php', '-r', self::BATCH_SCRIPT], input: json_encode($payload), timeout: $timeout);
        $output = json_decode($result->output(), true);

        if ($result->failed() || !is_array($output)) {
            throw new RuntimeException('Preview container command failed: ' . Str::limit(trim($result->errorOutput() ?: $result->output()), 500));
        }

        return $output;
    }

    private function describeCheck(string $name, array $result): ?string
    {
        if (isset($result['lint'])) {
            $message = trim(Str::before($result['lint'], 'Errors parsing'));

            return "{$name}: PHP syntax error: " . Str::limit($message, 400);
        }

        $status = $result['status'] ?? '';
        if ($status === '200') {
            return null;
        }

        $route = '/' . self::kebab($name);
        $message = preg_match('/\]\s+\w+\.(?:ERROR|CRITICAL|EMERGENCY):\s+(.+?)(?:\s+\{"|\s*$)/m', $result['log'] ?? '', $matches)
            ? Str::limit(trim($matches[1]), 500)
            : null;

        return $message
            ? "{$name}: rendering {$route} failed (HTTP {$status}): {$message}"
            : "{$name}: rendering {$route} returned HTTP {$status}";
    }

    /**
     * Run a command in the container as the web server user, from the app root.
     * A container paused for inactivity is resumed and the command retried.
     */
    private function exec(string $containerId, array $command, ?string $input = null, int $timeout = 60): ProcessResult
    {
        $args = ['exec', '-u', 'www-data', '-w', self::APP_ROOT];
        if ($input !== null) {
            $args[] = '-i';
        }

        $result = $this->docker([...$args, $containerId, ...$command], $input, $timeout);
        if ($result->failed() && str_contains($result->errorOutput(), 'is paused')) {
            $this->docker(['unpause', $containerId]);
            $result = $this->docker([...$args, $containerId, ...$command], $input, $timeout);
        }

        return $result;
    }

    private function docker(array $args, ?string $input = null, int $timeout = 60): ProcessResult
    {
        $process = Process::timeout($timeout);
        if ($input !== null) {
            $process = $process->input($input);
        }

        return $process->run(['docker', ...$args]);
    }
}
