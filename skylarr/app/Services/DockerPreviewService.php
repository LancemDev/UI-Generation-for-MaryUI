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
     * Inject generated code into a project container.
     */
    public function injectCode(Project $project, string $code, string $componentName): bool
    {
        try {
            if (!$project->container_id || !$this->isContainerRunning($project->container_id)) {
                throw new \Exception("Container not running for project {$project->id}");
            }
            
            // Create component file in container
            $componentPath = "/var/www/html/app/Livewire/Generated/{$componentName}.php";
            $this->runDockerCommand([
                'exec', $project->container_id,
                'sh', '-c', "mkdir -p /var/www/html/app/Livewire/Generated"
            ]);
            
            // Write the component code
            $escapedCode = escapeshellarg($code);
            $this->runDockerCommand([
                'exec', $project->container_id,
                'sh', '-c', "echo {$escapedCode} > {$componentPath}"
            ]);
            
            // Register the component in the container's service provider
            $this->registerComponentInContainer($project->container_id, $componentName);
            
            // Restart the container's Laravel application
            $this->restartLaravelInContainer($project->container_id);
            
            Log::info("Successfully injected code into project {$project->id}", [
                'component_name' => $componentName,
                'container_id' => $project->container_id
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to inject code into project {$project->id}", [
                'error' => $e->getMessage(),
                'component_name' => $componentName
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
     */
    public function listProjectFiles(string $containerId): array
    {
        $files = [];
        
        try {
            // Get resources directory files
            $result = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'find /var/www/html/resources -type f \( -name "*.blade.php" -o -name "*.php" -o -name "*.js" -o -name "*.css" \) | head -50'
            ]);
            
            if ($result->successful()) {
                $lines = explode("\n", trim($result->output()));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $files[] = $line;
                    }
                }
            }
            
            // Get app/Http directory files
            $result2 = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'find /var/www/html/app/Http -type f -name "*.php" | head -20'
            ]);
            
            if ($result2->successful()) {
                $lines = explode("\n", trim($result2->output()));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $files[] = $line;
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to list files", ['error' => $e->getMessage()]);
        }
        
        return $files;
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
