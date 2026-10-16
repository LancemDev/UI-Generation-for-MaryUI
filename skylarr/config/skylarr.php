<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI service
    |--------------------------------------------------------------------------
    |
    | The Python service in scripts/ that turns chat messages into components.
    | The token must match AI_SERVICE_TOKEN in scripts/.env.
    |
    */

    'ai' => [
        'url' => env('AI_SERVICE_URL', env('PY_BACKEND_URL', 'http://127.0.0.1:8001')),
        'token' => env('AI_SERVICE_TOKEN'),
        'timeout' => (int) env('AI_SERVICE_TIMEOUT', 180),
        // How many times a failing component is sent back to the model with its errors.
        'max_repair_attempts' => (int) env('AI_MAX_REPAIR_ATTEMPTS', 2),
        'history_messages' => (int) env('AI_HISTORY_MESSAGES', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Administrators
    |--------------------------------------------------------------------------
    |
    | Comma-separated emails allowed to use container maintenance endpoints
    | (cleanup, remove-all, remove-by-port).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Live updates
    |--------------------------------------------------------------------------
    |
    | With a WebSocket broadcaster (Reverb) configured, the chat and preview get
    | progress pushed to them. Otherwise they fall back to polling.
    |
    */

    'realtime' => in_array(env('BROADCAST_CONNECTION'), ['reverb', 'pusher', 'ably'], true),

    'admin_emails' => array_filter(array_map('trim', explode(',', (string) env('SKYLARR_ADMIN_EMAILS', '')))),

    /*
    |--------------------------------------------------------------------------
    | Preview containers
    |--------------------------------------------------------------------------
    */

    'preview' => [
        'image' => env('PREVIEW_IMAGE', 'skylarr-preview:latest'),
        // Interface published ports bind to. Keep 127.0.0.1 unless a reverse proxy needs otherwise.
        'bind_host' => env('PREVIEW_BIND_HOST', '127.0.0.1'),
        // Host used in preview URLs shown to the browser.
        'public_host' => env('PREVIEW_HOST', '127.0.0.1'),
        'base_port' => (int) env('PREVIEW_BASE_PORT', 8101),
        'port_range' => (int) env('PREVIEW_PORT_RANGE', 500),
        // Docker network for previews. Point this at a network without outbound access
        // to stop generated code from reaching the internet.
        'network' => env('PREVIEW_NETWORK'),
        'memory' => env('PREVIEW_MEMORY', '512m'),
        'cpus' => env('PREVIEW_CPUS', '1'),
        'pids_limit' => (int) env('PREVIEW_PIDS_LIMIT', 256),
        'max_containers_per_user' => (int) env('PREVIEW_MAX_CONTAINERS_PER_USER', 5),
        // Pause (no CPU, instant resume) after this many idle minutes; 0 disables.
        'pause_after_minutes' => (int) env('PREVIEW_PAUSE_MINUTES', 15),
        // Remove the container (its components are restored on next use) after this many idle hours.
        'idle_hours' => (int) env('PREVIEW_IDLE_HOURS', 24),
        // Pre-started containers handed to new projects so the first preview is instant.
        'pool_size' => (int) env('PREVIEW_POOL_SIZE', 2),
        'ready_timeout' => (int) env('PREVIEW_READY_TIMEOUT', 45),
    ],

];
