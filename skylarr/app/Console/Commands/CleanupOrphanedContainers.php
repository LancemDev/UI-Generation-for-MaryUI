<?php

namespace App\Console\Commands;

use App\Services\DockerPreviewService;
use Illuminate\Console\Command;

class CleanupOrphanedContainers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'docker:cleanup-orphaned {--port= : Remove container on specific port} {--all : Remove all Skylarr containers}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up orphaned Docker containers (containers without database records)';

    /**
     * Execute the console command.
     */
    public function handle(DockerPreviewService $dockerService)
    {
        if ($this->option('all')) {
            $this->info('Removing all Skylarr containers...');
            $removed = $dockerService->removeAllSkylarrContainers();
            $this->info("Removed {$removed} containers.");
            return 0;
        }

        if ($port = $this->option('port')) {
            $port = (int) $port;
            $this->info("Removing container on port {$port}...");
            $removed = $dockerService->removeContainerByPort($port);
            
            if ($removed) {
                $this->info("Container on port {$port} removed successfully.");
            } else {
                $this->warn("No container found on port {$port}.");
            }
            return 0;
        }

        $this->info('Cleaning up orphaned containers...');
        $cleaned = $dockerService->cleanupOrphanedContainers();
        $this->info("Cleaned up {$cleaned} orphaned containers.");
        
        return 0;
    }
}
