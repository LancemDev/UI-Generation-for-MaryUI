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

Schedule::command('preview:cleanup-idle')->hourly();
Schedule::command('preview:fill-pool')->everyFiveMinutes()->withoutOverlapping();
