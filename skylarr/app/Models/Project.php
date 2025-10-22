<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'container_id',
        'container_name',
        'port',
        'preview_url',
        'status',
        'metadata',
        'last_accessed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_accessed_at' => 'datetime',
    ];

    protected $hidden = [
        'container_id',
    ];

    /**
     * Get the user that owns the project.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the chat threads for the project.
     */
    public function chatThreads(): HasMany
    {
        return $this->hasMany(ChatThread::class);
    }

    /**
     * Check if the project container is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Update the last accessed timestamp.
     */
    public function touchLastAccessed(): void
    {
        $this->update(['last_accessed_at' => now()]);
    }

    /**
     * Get the preview URL with fallback.
     */
    public function getPreviewUrlAttribute(): ?string
    {
        if ($this->attributes['preview_url']) {
            return $this->attributes['preview_url'];
        }

        if ($this->port) {
            return "http://localhost:{$this->port}";
        }

        return null;
    }
}
