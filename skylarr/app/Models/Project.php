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

    /**
     * Check if code generation is in progress.
     */
    public function isGenerating(): bool
    {
        $metadata = $this->metadata ?? [];
        return ($metadata['generation']['is_generating'] ?? false) === true;
    }

    /**
     * Get generation state from metadata.
     */
    public function getGenerationState(): array
    {
        $metadata = $this->metadata ?? [];
        return $metadata['generation'] ?? [
            'is_generating' => false,
            'prompt' => null,
            'started_at' => null,
            'generated_code' => null,
            'component_name' => null,
            'preview_url' => null,
        ];
    }

    /**
     * Set generation state in metadata.
     */
    public function setGenerationState(array $state): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['generation'] = array_merge($metadata['generation'] ?? [], $state);
        $this->update(['metadata' => $metadata]);
    }

    /**
     * Start generation tracking.
     */
    public function startGeneration(string $prompt): void
    {
        $this->setGenerationState([
            'is_generating' => true,
            'prompt' => $prompt,
            'started_at' => now()->toIso8601String(),
            'generated_code' => null,
            'component_name' => null,
            'preview_url' => null,
        ]);
    }

    /**
     * Complete generation tracking.
     */
    public function completeGeneration(string $componentName, ?string $generatedCode = null, ?string $previewUrl = null): void
    {
        $this->setGenerationState([
            'is_generating' => false,
            'component_name' => $componentName,
            'generated_code' => $generatedCode,
            'preview_url' => $previewUrl,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Clear generation state.
     */
    public function clearGenerationState(): void
    {
        $metadata = $this->metadata ?? [];
        unset($metadata['generation']);
        $this->update(['metadata' => $metadata]);
    }

    /**
     * Get all generated components and their routes.
     */
    public function getComponents(): array
    {
        $metadata = $this->metadata ?? [];
        return $metadata['components'] ?? [];
    }

    /**
     * Add a component and its route.
     */
    public function addComponent(string $componentName, string $route, string $routeName = null): void
    {
        $metadata = $this->metadata ?? [];
        $components = $metadata['components'] ?? [];
        
        $kebabName = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $componentName));
        $routeName = $routeName ?? $kebabName;
        
        $components[] = [
            'name' => $componentName,
            'kebab_name' => $kebabName,
            'route' => $route,
            'route_name' => $routeName,
            'created_at' => now()->toIso8601String(),
        ];
        
        $metadata['components'] = $components;
        $this->update(['metadata' => $metadata]);
    }

    /**
     * Get routes for all components.
     */
    public function getRoutes(): array
    {
        $components = $this->getComponents();
        return array_map(function ($component) {
            return [
                'name' => $component['route_name'] ?? $component['kebab_name'],
                'url' => $component['route'],
                'component' => $component['name'],
            ];
        }, $components);
    }
}
