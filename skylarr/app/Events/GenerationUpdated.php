<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the chat and preview panels of a project that something changed: a new
 * stage, more of the streamed reply, or a finished generation. Listeners re-read
 * state from the database, so the payload stays small.
 */
class GenerationUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public int $projectId,
        public ?int $messageId = null,
        public ?string $stage = null,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("project.{$this->projectId}");
    }

    /**
     * Broadcast when live updates are configured. A WebSocket outage must never
     * fail a generation: the UI falls back to its slow heartbeat poll.
     */
    public static function send(int $projectId, ?int $messageId = null, ?string $stage = null): void
    {
        if (!config('skylarr.realtime')) {
            return;
        }

        try {
            broadcast(new self($projectId, $messageId, $stage));
        } catch (Throwable $e) {
            Log::warning('[BROADCAST] Failed to send generation update', ['project_id' => $projectId, 'error' => $e->getMessage()]);
        }
    }
}
