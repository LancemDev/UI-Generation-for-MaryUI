<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class DockerPreviewService
{
    private const BASE_PORT = 8001;
    private const MAX_CONTAINERS_PER_USER = 5;
    private const CONTAINER_TIMEOUT_HOURS = 24;
    
    private array $usedPorts = [];
    
    /**
     * Create a new preview container for a project.
     */
    public function createProjectContainer(Project $project): string
    {
        try {
            $containerName = $this->generateContainerName($project);
            $port = $this->getAvailablePort();
            
            Log::info("Creating Docker container for project {$project->id}", [
                'container_name' => $containerName,
                'port' => $port,
                'user_id' => $project->user_id
            ]);
            
            // Create the container
            $result = $this->runDockerCommand([
                'run',
                '-d',
                '--name', $containerName,
                '-p', "{$port}:80",
                '--label', "user_id={$project->user_id}",
                '--label', "project_id={$project->id}",
                '--label', "created_at=" . now()->toISOString(),
                'skylarr-preview:latest'
            ]);
            
            if ($result->failed()) {
                throw new \Exception("Failed to create container: " . $result->errorOutput());
            }
            
            $containerId = trim($result->output());
            $previewUrl = "http://localhost:{$port}";
            
            // Update project with container info
            $project->update([
                'container_id' => $containerId,
                'container_name' => $containerName,
                'port' => $port,
                'preview_url' => $previewUrl,
                'status' => 'active',
                'last_accessed_at' => now()
            ]);
            
            // Wait for container to be ready
            $this->waitForContainerReady($containerId);
            
            Log::info("Successfully created container for project {$project->id}", [
                'container_id' => $containerId,
                'preview_url' => $previewUrl
            ]);
            
            return $previewUrl;
            
        } catch (\Exception $e) {
            Log::error("Failed to create container for project {$project->id}", [
                'error' => $e->getMessage(),
                'project' => $project->toArray()
            ]);
            
            $project->update(['status' => 'error']);
            throw $e;
        }
    }
    
    /**
     * Get existing container for a project or create new one.
     */
    public function getOrCreateProjectContainer(Project $project): string
    {
        // Check if project already has an active container
        if ($project->container_id && $project->isActive()) {
            if ($this->isContainerRunning($project->container_id)) {
                $project->touchLastAccessed();
                return $project->preview_url;
            }
        }
        
        // Clean up any existing container
        if ($project->container_id) {
            $this->removeContainer($project->container_id);
        }
        
        // Create new container
        return $this->createProjectContainer($project);
    }
    
    /**
     * Validate that code is a Laravel Livewire component.
     */
    private function validateLivewireCode(string $code): bool
    {
        // Must be PHP code
        if (!str_contains($code, '<?php') && !str_starts_with(trim($code), '<?php')) {
            Log::warning('[DOCKER] Code does not start with <?php', ['code_preview' => substr($code, 0, 100)]);
            return false;
        }
        
        // Must have Livewire namespace or use statement
        if (!str_contains($code, 'Livewire') && !str_contains($code, 'Livewire\\Component')) {
            Log::warning('[DOCKER] Code does not contain Livewire references');
            return false;
        }
        
        // Must extend Component
        if (!preg_match('/extends\s+(\\\\)?Component/', $code)) {
            Log::warning('[DOCKER] Code does not extend Component');
            return false;
        }
        
        // Must have App\Livewire namespace
        if (!str_contains($code, 'namespace App\\Livewire') && !str_contains($code, 'namespace App\\\\Livewire')) {
            Log::warning('[DOCKER] Code does not have App\\Livewire namespace');
            return false;
        }
        
        // Forbidden frameworks/languages
        $forbidden = [
            'import React',
            'from "react"',
            'import Vue',
            'from "vue"',
            'export default',
            'function Component',
            'const Component',
            'class Component extends React',
            'class Component extends Vue',
            'def ',
            'class.*extends.*React',
            'class.*extends.*Vue',
            'useState',
            'useEffect',
            '<script>',
            'jsx',
            'tsx',
        ];
        
        foreach ($forbidden as $pattern) {
            if (preg_match('/' . preg_quote($pattern, '/') . '/i', $code)) {
                Log::warning('[DOCKER] Code contains forbidden framework pattern', ['pattern' => $pattern]);
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Parse code to extract component class name and view name.
     */
    private function parseComponentCode(string $code): array
    {
        $className = null;
        $viewName = null;
        
        // Extract class name
        if (preg_match('/class\s+([A-Z][a-zA-Z0-9]*)\s+extends/', $code, $matches)) {
            $className = $matches[1];
        }
        
        // Extract view name from render() method
        if (preg_match("/view\(['\"]([^'\"]+)['\"]\)/", $code, $matches)) {
            $viewName = $matches[1];
        } elseif (preg_match("/return\s+view\(['\"]([^'\"]+)['\"]\)/", $code, $matches)) {
            $viewName = $matches[1];
        }
        
        return [
            'class_name' => $className,
            'view_name' => $viewName,
        ];
    }
    
    /**
     * Convert PascalCase to kebab-case.
     */
    private function toKebabCase(string $string): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $string));
    }
    
    /**
     * Inject generated code into a project container.
     * Organizes files according to Laravel 11 conventions.
     */
    public function injectCode(Project $project, string $code, string $componentName): bool
    {
        try {
            if (!$project->container_id || !$this->isContainerRunning($project->container_id)) {
                throw new \Exception("Container not running for project {$project->id}");
            }
            
            // Validate that the code is a Laravel Livewire component
            if (!$this->validateLivewireCode($code)) {
                Log::error("Generated code is not a valid Laravel Livewire component", [
                    'project_id' => $project->id,
                    'code_preview' => substr($code, 0, 200)
                ]);
                throw new \Exception("Generated code is not a valid Laravel Livewire component. Only Laravel Livewire components are supported.");
            }
            
            // Parse the code to extract class name and view name
            $parsed = $this->parseComponentCode($code);
            $actualClassName = $parsed['class_name'] ?? $componentName;
            $viewName = $parsed['view_name'];
            
            // Ensure component name matches the actual class name
            if ($parsed['class_name']) {
                $componentName = $parsed['class_name'];
            }
            
            // Create component file in app/Livewire/ (Laravel 11 convention)
            $componentPath = "/var/www/html/app/Livewire/{$componentName}.php";
            $this->runDockerCommand([
                'exec', $project->container_id,
                'sh', '-c', "mkdir -p /var/www/html/app/Livewire"
            ]);
            
            // Write the component code
            $escapedCode = escapeshellarg($code);
            $this->runDockerCommand([
                'exec', $project->container_id,
                'sh', '-c', "echo {$escapedCode} > {$componentPath}"
            ]);
            
            // If view name is specified, create the view file
            if ($viewName) {
                // Extract view path (e.g., 'livewire.my-component' -> 'livewire/my-component.blade.php')
                $viewPath = str_replace('.', '/', $viewName);
                if (!str_ends_with($viewPath, '.blade.php')) {
                    $viewPath .= '.blade.php';
                }
                $fullViewPath = "/var/www/html/resources/views/{$viewPath}";
                
                // Create view directory if needed
                $viewDir = dirname($fullViewPath);
                $this->runDockerCommand([
                    'exec', $project->container_id,
                    'sh', '-c', "mkdir -p {$viewDir}"
                ]);
                
                // Create a basic view file if it doesn't exist
                $kebabName = $this->toKebabCase($componentName);
                $viewContent = <<<BLADE
<div>
    <h2 class="text-2xl font-bold mb-4">{{ \$componentName ?? '{$componentName}' }}</h2>
    <!-- Component view content -->
</div>
BLADE;
                $escapedView = escapeshellarg($viewContent);
                $this->runDockerCommand([
                    'exec', $project->container_id,
                    'sh', '-c', "echo {$escapedView} > {$fullViewPath}"
                ]);
            } else {
                // Create default view if no view specified
                $kebabName = $this->toKebabCase($componentName);
                $defaultViewPath = "/var/www/html/resources/views/livewire/{$kebabName}.blade.php";
                $this->runDockerCommand([
                    'exec', $project->container_id,
                    'sh', '-c', "mkdir -p /var/www/html/resources/views/livewire"
                ]);
                
                $viewContent = <<<BLADE
<div>
    <h2 class="text-2xl font-bold mb-4">{{ \$componentName ?? '{$componentName}' }}</h2>
    <!-- Component view content -->
</div>
BLADE;
                $escapedView = escapeshellarg($viewContent);
                $this->runDockerCommand([
                    'exec', $project->container_id,
                    'sh', '-c', "echo {$escapedView} > {$defaultViewPath}"
                ]);
            }
            
            // Register the component in the container's service provider
            $this->registerComponentInContainer($project->container_id, $componentName);
            
            // Restart the container's Laravel application
            $this->restartLaravelInContainer($project->container_id);
            
            Log::info("Successfully injected code into project {$project->id}", [
                'component_name' => $componentName,
                'component_path' => $componentPath,
                'container_id' => $project->container_id
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to inject code into project {$project->id}", [
                'error' => $e->getMessage(),
                'component_name' => $componentName,
                'trace' => $e->getTraceAsString()
            ]);
            
            return false;
        }
    }
    
    /**
     * Remove a container.
     */
    public function removeContainer(string $containerId): bool
    {
        try {
            $result = $this->runDockerCommand(['rm', '-f', $containerId]);
            
            if ($result->successful()) {
                Log::info("Successfully removed container", ['container_id' => $containerId]);
                return true;
            }
            
            Log::warning("Failed to remove container", [
                'container_id' => $containerId,
                'error' => $result->errorOutput()
            ]);
            
            return false;
            
        } catch (\Exception $e) {
            Log::error("Exception while removing container", [
                'container_id' => $containerId,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Get all containers for a user.
     */
    public function getUserContainers(int $userId): array
    {
        $result = $this->runDockerCommand([
            'ps', '-a',
            '--filter', "label=user_id={$userId}",
            '--format', '{{.ID}} {{.Names}} {{.Status}} {{.Ports}}'
        ]);
        
        if ($result->failed()) {
            return [];
        }
        
        $containers = [];
        $lines = explode("\n", trim($result->output()));
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            $parts = explode(' ', $line, 4);
            if (count($parts) >= 4) {
                $containers[] = [
                    'id' => $parts[0],
                    'name' => $parts[1],
                    'status' => $parts[2],
                    'ports' => $parts[3]
                ];
            }
        }
        
        return $containers;
    }
    
    /**
     * Clean up expired containers.
     */
    public function cleanupExpiredContainers(): int
    {
        $cutoffTime = now()->subHours(self::CONTAINER_TIMEOUT_HOURS);
        
        $result = $this->runDockerCommand([
            'ps', '-a',
            '--filter', "label=created_at",
            '--format', '{{.ID}} {{.Label "created_at"}}'
        ]);
        
        if ($result->failed()) {
            return 0;
        }
        
        $cleaned = 0;
        $lines = explode("\n", trim($result->output()));
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            $parts = explode(' ', $line, 2);
            if (count($parts) === 2) {
                $containerId = $parts[0];
                $createdAt = $parts[1];
                
                if (strtotime($createdAt) < $cutoffTime->timestamp) {
                    if ($this->removeContainer($containerId)) {
                        $cleaned++;
                    }
                }
            }
        }
        
        Log::info("Cleaned up expired containers", ['count' => $cleaned]);
        return $cleaned;
    }
    
    /**
     * Check if user can create more containers.
     */
    public function canUserCreateContainer(int $userId): bool
    {
        $userContainers = $this->getUserContainers($userId);
        $activeContainers = array_filter($userContainers, fn($c) => str_contains($c['status'], 'Up'));
        
        return count($activeContainers) < self::MAX_CONTAINERS_PER_USER;
    }
    
    /**
     * Generate a unique container name for a project.
     */
    private function generateContainerName(Project $project): string
    {
        return "skylarr-user-{$project->user_id}-project-{$project->id}-" . Str::random(8);
    }
    
    /**
     * Get an available port for the container.
     */
    private function getAvailablePort(): int
    {
        $port = self::BASE_PORT;
        
        while ($port < self::BASE_PORT + 1000) {
            if (!$this->isPortInUse($port)) {
                $this->usedPorts[] = $port;
                Log::info('[DOCKER] Found available port', ['port' => $port]);
                return $port;
            }
            $port++;
        }
        
        throw new \Exception("No available ports found");
    }
    
    /**
     * Check if a port is in use.
     */
    private function isPortInUse(int $port): bool
    {
        // Check system ports using netstat or ss
        $result = Process::run(['sh', '-c', "netstat -ltn 2>/dev/null | grep ':{$port}' || ss -ltn 2>/dev/null | grep ':{$port}'"]);
        
        if ($result->successful() && !empty($result->output())) {
            return true;
        }
        
        // Also check Docker containers
        $dockerResult = $this->runDockerCommand(['ps', '--format', '{{.Ports}}']);
        
        if ($dockerResult->successful()) {
            $output = $dockerResult->output();
            return str_contains($output, ":{$port}->");
        }
        
        return false;
    }
    
    /**
     * Check if a container is running.
     */
    public function isContainerRunning(string $containerId): bool
    {
        $result = $this->runDockerCommand(['ps', '-q', '-f', "id={$containerId}"]);
        return $result->successful() && !empty(trim($result->output()));
    }
    
    /**
     * Wait for container to be ready.
     */
    private function waitForContainerReady(string $containerId, int $maxWait = 60): void
    {
        $waited = 0;
        
        // Wait for container to be running
        while ($waited < 10 && !$this->isContainerRunning($containerId)) {
            sleep(1);
            $waited++;
        }
        
        if (!$this->isContainerRunning($containerId)) {
            throw new \Exception("Container did not start within 10 seconds");
        }
        
        Log::info('[DOCKER] Container is running', ['container_id' => $containerId]);
        
        // For now, just wait a bit and assume it's ready
        // The actual Laravel app will start inside the container
        sleep(5);
        
        Log::info('[DOCKER] Proceeding with container ready', ['container_id' => $containerId]);
    }
    
    /**
     * Register a component in the container's service provider.
     */
    private function registerComponentInContainer(string $containerId, string $componentName): void
    {
        $serviceProviderPath = '/var/www/html/app/Providers/AppServiceProvider.php';
        
        // This is a simplified approach - in production, you'd want more sophisticated
        // component registration
        $this->runDockerCommand([
            'exec', $containerId,
            'sh', '-c', "echo '// Auto-generated component: {$componentName}' >> {$serviceProviderPath}"
        ]);
    }
    
    /**
     * Restart Laravel application in container.
     */
    private function restartLaravelInContainer(string $containerId): void
    {
        // Clear caches and restart
        $this->runDockerCommand([
            'exec', $containerId,
            'sh', '-c', 'cd /var/www/html && php artisan config:clear && php artisan route:clear'
        ]);
    }
    
    /**
     * List project files from container.
     * Organized by Laravel 11 folder structure.
     */
    public function listProjectFiles(string $containerId): array
    {
        $files = [];
        
        try {
            // Get app/Livewire files (components)
            $result = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'find /var/www/html/app/Livewire -type f -name "*.php" | head -30'
            ]);
            
            if ($result->successful()) {
                $lines = explode("\n", trim($result->output()));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $files[] = $line;
                    }
                }
            }
            
            // Get resources/views/livewire files (Blade templates)
            $result2 = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'find /var/www/html/resources/views/livewire -type f -name "*.blade.php" | head -30'
            ]);
            
            if ($result2->successful()) {
                $lines = explode("\n", trim($result2->output()));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $files[] = $line;
                    }
                }
            }
            
            // Get app/Http directory files (controllers, etc.)
            $result3 = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'find /var/www/html/app/Http -type f -name "*.php" | head -20'
            ]);
            
            if ($result3->successful()) {
                $lines = explode("\n", trim($result3->output()));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $files[] = $line;
                    }
                }
            }
            
            // Get routes
            $result4 = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'find /var/www/html/routes -type f -name "*.php" | head -10'
            ]);
            
            if ($result4->successful()) {
                $lines = explode("\n", trim($result4->output()));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $files[] = $line;
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to list files", ['error' => $e->getMessage()]);
        }
        
        return array_unique($files);
    }
    
    /**
     * Read a file from container.
     */
    public function readFileFromContainer(string $containerId, string $filePath): string
    {
        try {
            $result = $this->runDockerCommand([
                'exec', $containerId,
                'cat', $filePath
            ]);
            
            if ($result->successful()) {
                return $result->output();
            }
            
            return '';
        } catch (\Exception $e) {
            Log::error("Failed to read file", ['file' => $filePath, 'error' => $e->getMessage()]);
            return '';
        }
    }
    
    /**
     * Run a Docker command.
     */
    private function runDockerCommand(array $command): \Illuminate\Process\ProcessResult
    {
        $fullCommand = array_merge(['docker'], $command);
        
        Log::debug("Running Docker command", ['command' => implode(' ', $fullCommand)]);
        
        return Process::run($fullCommand);
    }
}
