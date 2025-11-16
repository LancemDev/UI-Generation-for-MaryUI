<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Auth;

class NotificationService
{
    /**
     * Create a notification for the current user.
     */
    public static function create(
        string $type,
        string $title,
        string $message,
        ?int $projectId = null,
        array $metadata = []
    ): Notification {
        return Notification::create([
            'user_id' => Auth::id(),
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
        array $metadata = []
    ): Notification {
        return self::create('success', $title, $message, $projectId, $metadata);
    }

    /**
     * Create an error notification.
     */
    public static function error(
        string $title,
        string $message,
        ?int $projectId = null,
        array $metadata = []
    ): Notification {
        return self::create('error', $title, $message, $projectId, $metadata);
    }

    /**
     * Create a warning notification.
     */
    public static function warning(
        string $title,
        string $message,
        ?int $projectId = null,
        array $metadata = []
    ): Notification {
        return self::create('warning', $title, $message, $projectId, $metadata);
    }

    /**
     * Create an info notification.
     */
    public static function info(
        string $title,
        string $message,
        ?int $projectId = null,
        array $metadata = []
    ): Notification {
        return self::create('info', $title, $message, $projectId, $metadata);
    }
}

