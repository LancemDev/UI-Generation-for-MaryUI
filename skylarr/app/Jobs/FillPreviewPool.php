<?php

namespace App\Jobs;

use App\Services\DockerPreviewService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Top up the pool of idle preview containers after one is claimed.
 */
class FillPreviewPool implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function handle(DockerPreviewService $preview): void
    {
        $preview->fillPool();
    }
}
