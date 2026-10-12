<?php

namespace App\Livewire;

use App\Models\Project;
use App\Services\DockerPreviewService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Code + live preview panel. Generation itself runs in the ProcessChatMessage
 * job; this component follows the project's generation state by polling.
 */
class CodeGenerationEngine extends Component
{
    use Toast;

    public ?Project $currentProject = null;
    public ?int $projectId = null;
    public string $previewUrl = '';
    public string $fileContent = '';
    public string $componentName = '';
    public bool $isGenerating = false;
    public bool $previewReady = false;
    public string $activeTab = 'preview';
    public string $generationStatus = '';
    public ?string $lastCompletedAt = null;
    public array $projectFiles = [];
    public array $projectFilesTree = [];
    public string $selectedFilePath = '';
    public string $selectedRoute = '';
    public string $selectedTheme = 'light';
    public string $selectingTheme = '';

    private array $themeList = [
        'light', 'dark', 'cupcake', 'bumblebee', 'emerald', 'corporate', 'synthwave',
        'retro', 'cyberpunk', 'valentine', 'halloween', 'garden', 'forest', 'aqua',
        'lofi', 'pastel', 'fantasy', 'wireframe', 'black', 'luxury', 'dracula',
        'cmyk', 'autumn', 'business', 'acid', 'lemonade', 'night', 'coffee',
        'winter', 'dim', 'nord', 'sunset', 'caramellatte', 'abyss', 'silk',
    ];

    /** Themes formatted for MaryUI select/dropdown components. */
    public array $availableThemes = [];

    public function mount(?int $projectId = null): void
    {
        $this->projectId = $projectId;
        $this->availableThemes = collect($this->themeList)
            ->map(fn ($theme) => ['id' => $theme, 'name' => ucfirst($theme)])
            ->all();

        if ($this->projectId) {
            $this->loadProject();
        }
    }

    #[On('projectChanged')]
    public function handleProjectChanged($projectData): void
    {
        $this->projectId = $projectData['id'];
        $this->loadProject();
    }

    private function loadProject(): void
    {
        $this->currentProject = Project::where('user_id', Auth::id())->find($this->projectId)
            ?? $this->getOrCreateDefaultProject();

        $theme = $this->currentProject->metadata['selected_theme'] ?? null;
        if (in_array($theme, $this->themeList, true)) {
            $this->selectedTheme = $theme;
        }

        $state = $this->currentProject->getGenerationState();
        $this->lastCompletedAt = $state['completed_at'] ?? null;
        $this->isGenerating = (bool) ($state['is_generating'] ?? false);
        $this->generationStatus = $state['stage'] ?? '';

        $dockerService = app(DockerPreviewService::class);
        if ($this->currentProject->container_id && $dockerService->isContainerRunning($this->currentProject->container_id)) {
            $this->showComponent($state['component_name'] ?? null);
        }
    }

    /**
     * Called by wire:poll. Mirrors the job's progress and refreshes the preview when a run finishes.
     */
    public function checkGenerationStatus(): void
    {
        if (!$this->currentProject) {
            return;
        }

        $this->currentProject->refresh();
        $state = $this->currentProject->getGenerationState();

        $this->isGenerating = (bool) ($state['is_generating'] ?? false);
        $this->generationStatus = $state['stage'] ?? '';

        $completedAt = $state['completed_at'] ?? null;
        if ($completedAt && $completedAt !== $this->lastCompletedAt) {
            $this->lastCompletedAt = $completedAt;
            $this->showComponent($state['component_name'] ?? null);
            $this->activeTab = 'preview';
            $this->reloadIframe();
        }
    }

    #[On('generation-started')]
    public function onGenerationStarted(): void
    {
        $this->isGenerating = true;
        $this->generationStatus = 'queued';
    }

    #[On('generation-finished')]
    public function onGenerationFinished(): void
    {
        $this->checkGenerationStatus();
    }

    public function updatedSelectedRoute(string $route): void
    {
        if ($this->currentProject?->preview_url && str_starts_with($route, '/')) {
            $this->previewUrl = $this->currentProject->preview_url . $route;
        }
    }

    /**
     * "Refresh": make sure the container is running (restarting it from saved components if needed).
     */
    public function updatePreview(): void
    {
        if (!$this->currentProject) {
            return;
        }

        try {
            app(DockerPreviewService::class)->getOrCreateProjectContainer($this->currentProject);
            $this->currentProject->refresh();
            $this->showComponent($this->componentName ?: null);
            $this->reloadIframe();
        } catch (\Throwable $e) {
            Log::error('[PREVIEW] Refresh failed', ['project_id' => $this->currentProject->id, 'error' => $e->getMessage()]);
            $this->error('Could not start the preview: ' . $e->getMessage());
        }
    }

    public function saveEditedCode(): void
    {
        $relativePath = DockerPreviewService::editablePath($this->selectedFilePath);
        if (!$this->currentProject?->container_id || !$relativePath) {
            $this->error('Only Livewire component classes and views can be edited here.');

            return;
        }

        $dockerService = app(DockerPreviewService::class);

        try {
            $dockerService->writeFile($this->currentProject->container_id, $relativePath, $this->fileContent);
        } catch (\Throwable $e) {
            $this->error('Failed to save: ' . $e->getMessage());

            return;
        }

        $name = $this->componentForPath($relativePath);
        if (!$name) {
            $this->success('Saved.');
            $this->reloadIframe();

            return;
        }

        $source = $dockerService->readComponent($this->currentProject, $name);
        $kebab = DockerPreviewService::kebab($name);
        $this->currentProject->addComponent($name, "/{$kebab}", $kebab, $source['php'], $source['blade']);

        $errors = $dockerService->validateComponent($this->currentProject, $name);
        $errors
            ? $this->warning('Saved, but the preview has errors', implode("\n", $errors), timeout: 8000)
            : $this->success('Saved and preview updated.');
        $this->reloadIframe();
    }

    public function selectTheme(string $theme): void
    {
        if (!$this->currentProject || !in_array($theme, $this->themeList, true)) {
            return;
        }

        $this->selectingTheme = $theme;
        $this->selectedTheme = $theme;

        $metadata = $this->currentProject->metadata ?? [];
        $metadata['selected_theme'] = $theme;
        $this->currentProject->update(['metadata' => $metadata]);

        if ($this->currentProject->container_id) {
            try {
                app(DockerPreviewService::class)->updatePreviewTheme($this->currentProject->container_id, $theme);
                $this->reloadIframe();
            } catch (\Throwable $e) {
                $this->error('Failed to update theme: ' . $e->getMessage());
            }
        }

        $this->js("setTimeout(() => \$wire.set('selectingTheme', ''), 1000);");
    }

    public function stopPreview(): void
    {
        if (!$this->currentProject?->container_id) {
            return;
        }

        $dockerService = app(DockerPreviewService::class);
        $dockerService->removeContainer($this->currentProject->container_id);
        $dockerService->detach($this->currentProject);

        $this->previewUrl = '';
        $this->previewReady = false;
        $this->projectFiles = [];
        $this->projectFilesTree = [];
        $this->success('Preview stopped');
    }

    public function selectFile(string $filePath): void
    {
        if (!$this->currentProject?->container_id) {
            return;
        }

        $this->selectedFilePath = $filePath;
        $this->fileContent = app(DockerPreviewService::class)->readFileFromContainer($this->currentProject->container_id, $filePath);
    }

    public function loadProjectFiles(): void
    {
        if (!$this->currentProject?->container_id) {
            $this->projectFiles = [];
            $this->projectFilesTree = [];

            return;
        }

        $this->projectFiles = app(DockerPreviewService::class)->listProjectFiles($this->currentProject->container_id);
        $this->projectFilesTree = $this->organizeFilesIntoTree($this->projectFiles);
    }

    public function getComponentsProperty(): array
    {
        return $this->currentProject?->getComponents() ?? [];
    }

    public function render()
    {
        return view('livewire.code-generation-engine');
    }

    /**
     * Point the preview and code view at a component (or the project's base URL).
     */
    private function showComponent(?string $name): void
    {
        $baseUrl = $this->currentProject->preview_url;
        if (!$baseUrl) {
            return;
        }

        $this->previewReady = true;
        $this->componentName = $name ?? '';
        $this->selectedRoute = $name ? '/' . DockerPreviewService::kebab($name) : '';
        $this->previewUrl = $baseUrl . $this->selectedRoute;

        $this->loadProjectFiles();
        if ($name) {
            $path = DockerPreviewService::APP_ROOT . "/app/Livewire/{$name}.php";
            if (in_array($path, $this->projectFiles, true)) {
                $this->selectFile($path);
            }
        }
    }

    private function componentForPath(string $relativePath): ?string
    {
        foreach ($this->currentProject->getComponents() as $component) {
            if (in_array($relativePath, DockerPreviewService::componentPaths($component['name']), true)) {
                return $component['name'];
            }
        }

        return null;
    }

    private function reloadIframe(): void
    {
        $this->js("setTimeout(() => { const f = document.getElementById('preview-iframe'); if (f) { f.src = f.src; } }, 300);");
    }

    private function getOrCreateDefaultProject(): Project
    {
        return Project::where('user_id', Auth::id())->latest('last_accessed_at')->latest()->first()
            ?? Project::create([
                'user_id' => Auth::id(),
                'name' => 'My First Project',
                'description' => 'Default project for code generation',
                'status' => 'creating',
                'metadata' => ['components' => []],
            ]);
    }

    private function organizeFilesIntoTree(array $files): array
    {
        $tree = [];

        foreach ($files as $file) {
            $parts = explode('/', str_replace(DockerPreviewService::APP_ROOT . '/', '', $file));
            $current = &$tree;
            $path = '';

            foreach (array_slice($parts, 0, -1) as $part) {
                $path .= ($path ? '/' : '') . $part;
                $current[$part] ??= ['type' => 'folder', 'path' => DockerPreviewService::APP_ROOT . '/' . $path, 'children' => []];
                $current = &$current[$part]['children'];
            }

            $filename = end($parts);
            $current[$filename] = ['type' => 'file', 'path' => $file, 'name' => $filename];
            unset($current);
        }

        return $tree;
    }
}
