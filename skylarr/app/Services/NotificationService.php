<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Auth;

class NotificationService
{
    /**
     * Create a notification for a user (defaults to the authenticated user;
     * queued jobs have no session, so they pass $userId explicitly).
     */
    public static function create(
        string $type,
        string $title,
        string $message,
        ?int $projectId = null,
        array $metadata = [],
        ?int $userId = null
    ): Notification {
        return Notification::create([
            'user_id' => $userId ?? Auth::id(),
            'project_id' => $projectId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Create a success notification.
     */
    public static function success(
        string $title,
        string $message,
        ?int $projectId = null,
        array $metadata = [],
        ?int $userId = null
    ): Notification {
        return self::create('success', $title, $message, $projectId, $metadata, $userId);
    }

    /**
     * Create an error notification.
     */
    public static function error(
        string $title,
        string $message,
        ?int $projectId = null,
        array $metadata = [],
        ?int $userId = null
    ): Notification {
        return self::create('error', $title, $message, $projectId, $metadata, $userId);
    }

    /**
     * Create a warning notification.
     */
    public static function warning(
        string $title,
        string $message,
        ?int $projectId = null,
        array $metadata = [],
        ?int $userId = null
    ): Notification {
        return self::create('warning', $title, $message, $projectId, $metadata, $userId);
    }

    /**
     * Create an info notification.
     */
    public static function info(
        string $title,
        string $message,
        ?int $projectId = null,
        array $metadata = [],
        ?int $userId = null
    ): Notification {
        return self::create('info', $title, $message, $projectId, $metadata, $userId);
    }
}

