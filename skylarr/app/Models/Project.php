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
     * Add or update a component and its route.
     * If component exists, updates it instead of creating duplicate.
     */
    public function addComponent(string $componentName, string $route, string $routeName = null, ?string $code = null, ?string $viewContent = null): void
    {
        $metadata = $this->metadata ?? [];
        $components = $metadata['components'] ?? [];
        
        $kebabName = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $componentName));
        $routeName = $routeName ?? $kebabName;
        
        // Check if component already exists
        $existingIndex = null;
        foreach ($components as $index => $component) {
            if ($component['name'] === $componentName) {
                $existingIndex = $index;
                break;
            }
        }
        
        if ($existingIndex !== null) {
            // Update existing component - preserve created_at and version history
            $existing = $components[$existingIndex];
            
            // Create version history entry from current state
            $versions = $existing['versions'] ?? [];
            if (!empty($existing['code']) || !empty($existing['view_content'])) {
                $versions[] = [
                    'code' => $existing['code'] ?? null,
                    'view_content' => $existing['view_content'] ?? null,
                    'updated_at' => $existing['updated_at'] ?? $existing['created_at'] ?? now()->toIso8601String(),
                ];
            }
            // Keep only last 10 versions
            $versions = array_slice($versions, -10);
            
            // Update the component
            $components[$existingIndex] = array_merge($existing, [
                'name' => $componentName,
                'kebab_name' => $kebabName,
                'route' => $route,
                'route_name' => $routeName,
                'updated_at' => now()->toIso8601String(),
                'versions' => $versions,
                'code' => $code ?? $existing['code'] ?? null,
                'view_content' => $viewContent ?? $existing['view_content'] ?? null,
            ]);
        } else {
            // New component
            $componentData = [
                'name' => $componentName,
                'kebab_name' => $kebabName,
                'route' => $route,
                'route_name' => $routeName,
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
                'versions' => [],
                'code' => $code,
                'view_content' => $viewContent,
            ];
            $components[] = $componentData;
        }
        
        $metadata['components'] = $components;
        $this->update(['metadata' => $metadata]);
    }

    /**
     * Get a specific component by name.
     */
    public function getComponent(string $componentName): ?array
    {
        $components = $this->getComponents();
        foreach ($components as $component) {
            if ($component['name'] === $componentName) {
                return $component;
            }
        }
        return null;
    }

    /**
     * Check if a component exists.
     */
    public function hasComponent(string $componentName): bool
    {
        return $this->getComponent($componentName) !== null;
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
