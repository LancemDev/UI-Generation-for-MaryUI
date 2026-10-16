<?php

use App\Services\DockerPreviewService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('preview:fill-pool', function (DockerPreviewService $preview) {
    $this->info('Started ' . $preview->fillPool() . ' pool container(s).');
})->purpose('Pre-start idle preview containers so new projects get a preview instantly');

Artisan::command('preview:cleanup-idle', function (DockerPreviewService $preview) {
    $this->info('Stopped ' . $preview->cleanupExpiredContainers() . ' idle preview container(s).');
})->purpose('Stop preview containers of projects nobody has opened recently');

Artisan::command('preview:pause-idle', function (DockerPreviewService $preview) {
    $this->info('Paused ' . $preview->pauseIdleContainers() . ' idle preview container(s).');
})->purpose('Pause preview containers unused for PREVIEW_PAUSE_MINUTES (they resume instantly)');

Artisan::command('preview:stats', function (DockerPreviewService $preview) {
    $stats = $preview->containerStats();
    $this->table(['container', 'cpu', 'memory', 'memory %'], array_map('array_values', $stats));
    $this->line(count($stats) . ' container(s). This is a single sample: run it under real load before tuning PREVIEW_MEMORY.');
})->purpose('Show live CPU and memory use of preview containers');

Schedule::command('preview:cleanup-idle')->hourly();
Schedule::command('preview:pause-idle')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('preview:fill-pool')->everyFiveMinutes()->withoutOverlapping();
