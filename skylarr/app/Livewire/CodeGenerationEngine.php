<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Project;
use App\Services\DockerPreviewService;
use App\Services\AiGateway;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Mary\Traits\Toast;

class CodeGenerationEngine extends Component
{
    use Toast;
    
    public ?Project $currentProject = null;
    public ?int $projectId = null;
    public string $previewUrl = '';
    public string $generatedCode = '';
    public string $componentName = '';
    public bool $isGenerating = false;
    public bool $previewReady = false;
    public string $activeTab = 'preview';
    public array $projectFiles = [];
    public array $projectFilesTree = [];
    public string $selectedFile = '';
    public string $selectedFilePath = '';
    public string $selectedRoute = '';
    public bool $showComponentSelectModal = false;
    public bool $showOverwriteConfirmModal = false;
    public ?string $pendingComponentName = null;
    public string $pendingPrompt = '';
    
    protected $listeners = [
        'codeGenerated' => 'handleCodeGenerated',
        'generate-code' => 'handleGenerateCodeRequest',
        'projectChanged' => 'handleProjectChanged',
        'project-updated' => 'handleProjectUpdated'
    ];
    
    public function mount(?int $projectId = null)
    {
        $this->projectId = $projectId;
        
        // Only load project if we have a valid projectId
        if ($this->projectId) {
            $this->loadProject();
            $this->initializePreview();
            $this->restoreGenerationState();
        }
    }
    
    public function updatedProjectId()
    {
        // When projectId changes, reload everything
        if ($this->projectId) {
            $this->resetComponentState();
            $this->loadProject();
            $this->initializePreview();
            $this->restoreGenerationState();
        }
    }
    
    private function resetComponentState()
    {
        // Reset all component state when switching projects
        $this->generatedCode = '';
        $this->componentName = '';
        $this->previewUrl = '';
        $this->isGenerating = false;
        $this->previewReady = false;
        $this->activeTab = 'preview';
        $this->projectFiles = [];
        $this->projectFilesTree = [];
        $this->selectedFile = '';
        $this->selectedFilePath = '';
        $this->selectedRoute = '';
        $this->currentProject = null;
    }

    /**
     * Restore generation state from database if generation was in progress or completed.
     */
    private function restoreGenerationState(): void
    {
        if (!$this->currentProject) {
            return;
        }

        $state = $this->currentProject->getGenerationState();
        
        // Check if there's any generation state (in-progress or completed)
        $hasGenerationState = ($state['is_generating'] ?? false) || 
                              !empty($state['completed_at']) || 
                              !empty($state['generated_code']);
        
        if ($hasGenerationState) {
            // Restore state from database
            $this->generatedCode = $state['generated_code'] ?? '';
            $this->componentName = $state['component_name'] ?? '';
            $this->previewUrl = $state['preview_url'] ?? '';
            
            // Check if generation completed
            if (!empty($state['completed_at']) && !empty($state['generated_code'])) {
                $this->isGenerating = false;
                $this->previewReady = !empty($this->previewUrl);
                $this->activeTab = 'preview'; // Show preview tab by default for completed generation
                
                // Load project files if preview is ready
                        if ($this->previewReady) {
                            $this->loadProjectFiles();
                            
                            // Auto-select the generated file if it exists
                            if ($this->componentName) {
                                $generatedFilePath = "/var/www/html/app/Livewire/{$this->componentName}.php";
                                if (in_array($generatedFilePath, $this->projectFiles)) {
                                    $this->selectFile($generatedFilePath);
                                }
                            }
                            
                            // Set default route to the generated component
                            $routes = $this->currentProject->getRoutes();
                            foreach ($routes as $route) {
                                if ($route['component'] === $this->componentName) {
                                    $this->selectedRoute = $route['url'];
                                    // Update preview URL to use proxy format
                                    $baseUrl = request()->getSchemeAndHttpHost();
                                    $routePath = ltrim($route['url'], '/');
                                    $this->previewUrl = "{$baseUrl}/preview/{$this->currentProject->id}" . ($routePath ? "/{$routePath}" : '');
                                    break;
                                }
                            }
                        }
                
                Log::info('[CODE_GEN] Restored completed generation state', [
                    'project_id' => $this->currentProject->id,
                    'component_name' => $this->componentName,
                    'preview_ready' => $this->previewReady,
                    'preview_url' => $this->previewUrl
                ]);
            } elseif ($state['is_generating'] ?? false) {
                // Generation was in progress - show loading state
                $this->isGenerating = true;
                $this->previewReady = false;
                $this->dispatch('showCodeCubeLoader');
                
                Log::info('[CODE_GEN] Restored in-progress generation state', [
                    'project_id' => $this->currentProject->id,
                    'prompt' => $state['prompt'] ?? 'unknown'
                ]);
            }
        }
    }
    
    public function handleProjectChanged($projectData)
    {
        $newProjectId = is_array($projectData) ? ($projectData['id'] ?? $projectData['selectedProjectId'] ?? null) : $projectData;
        
        if ($newProjectId && $newProjectId != $this->projectId) {
            $this->projectId = $newProjectId;
            $this->resetComponentState();
            $this->loadProject();
            $this->initializePreview();
            $this->restoreGenerationState();
        }
    }
    
    public function handleProjectUpdated($data)
    {
        // Handle project-updated event from CodeGenerator
        $newProjectId = is_array($data) ? ($data['selectedProjectId'] ?? $data['id'] ?? null) : $data;
        
        if ($newProjectId && $newProjectId != $this->projectId) {
            $oldProjectId = $this->projectId;
            $this->projectId = $newProjectId;
            $this->resetComponentState();
            $this->loadProject();
            $this->initializePreview();
            $this->restoreGenerationState();
            
            Log::info('[CODE_GEN] Project updated', [
                'old_project_id' => $oldProjectId,
                'new_project_id' => $newProjectId
            ]);
        }
    }
    
    private function loadProject()
    {
        if ($this->projectId) {
            $this->currentProject = Project::where('user_id', Auth::id())
                ->find($this->projectId);
        }
        
        if (!$this->currentProject) {
            $this->currentProject = $this->getOrCreateDefaultProject();
        }
    }
    
    public function generateCode(string $prompt, ?string $targetComponentName = null)
    {
        if (!$this->currentProject) {
            Log::error('[CODE_GEN] No project selected');
            $this->error('No project selected');
            return;
        }
        
        // If target component name is provided, check if it exists
        if ($targetComponentName) {
            $existingComponent = $this->currentProject->getComponent($targetComponentName);
            if ($existingComponent) {
                // Component exists - show confirmation
                $this->pendingComponentName = $targetComponentName;
                $this->pendingPrompt = $prompt;
                $this->showOverwriteConfirmModal = true;
                return;
            }
        }
        
        $this->doGenerateCode($prompt, $targetComponentName);
    }
    
    public function confirmOverwrite(): void
    {
        if ($this->pendingComponentName && $this->pendingPrompt) {
            $this->showOverwriteConfirmModal = false;
            $this->doGenerateCode($this->pendingPrompt, $this->pendingComponentName);
            $this->pendingComponentName = null;
            $this->pendingPrompt = '';
        }
    }
    
    public function cancelOverwrite(): void
    {
        $this->showOverwriteConfirmModal = false;
        $this->pendingComponentName = null;
        $this->pendingPrompt = '';
    }
    
    private function doGenerateCode(string $prompt, ?string $targetComponentName = null): void
    {
        Log::info('[CODE_GEN] Starting generation', [
            'project_id' => $this->currentProject->id,
            'prompt' => $prompt,
            'target_component' => $targetComponentName
        ]);
        
        $this->isGenerating = true;
        $this->previewReady = false;
        
        // Clear any previous generation state and start new generation
        $this->currentProject->clearGenerationState();
        $this->currentProject->startGeneration($prompt);
        
        // Show the code cube loader
        $this->dispatch('showCodeCubeLoader');
        
        try {
            // Generate code using AI
            $aiGateway = app(AiGateway::class);
            
            Log::info('[CODE_GEN] Calling AI Gateway');
            $response = $aiGateway->generateCode($prompt);
            
            Log::info('[CODE_GEN] AI Gateway response received', [
                'success' => $response['success'] ?? false,
                'has_code' => isset($response['code'])
            ]);
            
            if ($response['success']) {
                $this->generatedCode = $response['code'];
                // Use target component name if provided, otherwise use AI-generated name
                $this->componentName = $targetComponentName ?? $response['component_name'] ?? 'GeneratedComponent';
                
                Log::info('[CODE_GEN] Code generated successfully', [
                    'component_name' => $this->componentName,
                    'code_length' => strlen($this->generatedCode)
                ]);
                
                // Switch to code tab to show the generated code
                $this->activeTab = 'preview';
                
                // Create preview
                Log::info('[CODE_GEN] Starting preview creation');
                $this->createPreview();
                
                // Save completed generation state to database
                $this->currentProject->completeGeneration(
                    $this->componentName,
                    $this->generatedCode,
                    $this->previewUrl
                );
                
                // Create success notification
                NotificationService::success(
                    'Component Generated',
                    "Successfully generated component: {$this->componentName}",
                    $this->currentProject->id,
                    ['component_name' => $this->componentName]
                );
                
                // Dispatch event to refresh notifications
                $this->dispatch('notification-created');
                
                // Dispatch Livewire event (for Livewire listeners)
                $this->dispatch('code-generation-complete', [
                    'component_name' => $this->componentName,
                    'message' => 'Code generation completed successfully!'
                ]);
                
                // Dispatch browser event for Alpine.js listeners (sibling components)
                $this->js("window.dispatchEvent(new CustomEvent('code-generation-complete', { detail: " . json_encode([
                    'component_name' => $this->componentName,
                    'message' => 'Code generation completed successfully!'
                ]) . " }))");
                
                // Note: loadProjectFiles() and selectFile() are already called in createPreview()
                // But we need to ensure the route is updated correctly
                if ($this->previewUrl && $this->componentName) {
                    $routes = $this->currentProject->getRoutes();
                    foreach ($routes as $route) {
                        if ($route['component'] === $this->componentName) {
                            $this->selectedRoute = $route['url'];
                            // Update preview URL to use proxy format
                            $baseUrl = request()->getSchemeAndHttpHost();
                            $routePath = ltrim($route['url'], '/');
                            $this->previewUrl = "{$baseUrl}/preview/{$this->currentProject->id}" . ($routePath ? "/{$routePath}" : '');
                            break;
                        }
                    }
                }
                
                // Ensure generatedCode is loaded from the file if not already set
                if (empty($this->generatedCode) && $this->componentName && $this->currentProject && $this->currentProject->container_id) {
                    $generatedFilePath = "/var/www/html/app/Livewire/{$this->componentName}.php";
                    if (in_array($generatedFilePath, $this->projectFiles)) {
                        $this->selectFile($generatedFilePath);
                    }
                }
                
                Log::info('[CODE_GEN] Success events dispatched', [
                    'component_name' => $this->componentName,
                    'is_generating' => $this->isGenerating,
                    'preview_ready' => $this->previewReady,
                    'has_generated_code' => !empty($this->generatedCode),
                    'preview_url' => $this->previewUrl
                ]);
                
                $this->success('Code generated successfully!');
            } else {
                $errorMessage = $response['message'] ?? 'Unknown error occurred';
                Log::error('[CODE_GEN] Code generation failed', ['message' => $errorMessage]);
                
                // Create error notification
                NotificationService::error(
                    'Code Generation Failed',
                    $errorMessage,
                    $this->currentProject->id ?? null,
                    ['prompt' => $prompt]
                );
                
                // Dispatch event to refresh notifications
                $this->dispatch('notification-created');
                
                $this->error('Failed to generate code: ' . $errorMessage);
                
                // Dispatch Livewire event (for Livewire listeners)
                $this->dispatch('code-generation-failed', [
                    'message' => $errorMessage
                ]);
                
                // Dispatch browser event for Alpine.js listeners (sibling components)
                $this->js("window.dispatchEvent(new CustomEvent('code-generation-failed', { detail: " . json_encode([
                    'message' => $errorMessage
                ]) . " }))");
            }
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            Log::error('[CODE_GEN] Exception during generation', [
                'error' => $errorMessage,
                'trace' => $e->getTraceAsString()
            ]);
            
            // Create error notification
            NotificationService::error(
                'Code Generation Error',
                $errorMessage,
                $this->currentProject->id ?? null,
                ['prompt' => $prompt, 'exception' => get_class($e)]
            );
            
            // Dispatch event to refresh notifications
            $this->dispatch('notification-created');
            
            $this->error('Error generating code: ' . $errorMessage);
            
            // Dispatch Livewire event (for Livewire listeners)
            $this->dispatch('code-generation-failed', [
                'message' => $errorMessage
            ]);
            
            // Dispatch browser event for Alpine.js listeners (sibling components)
            $this->js("window.dispatchEvent(new CustomEvent('code-generation-failed', { detail: " . json_encode([
                'message' => $errorMessage
            ]) . " }))");
        } finally {
            $this->isGenerating = false;
            
            // Clear generation state if it failed (success case already handled above)
            if (!$this->previewReady && $this->currentProject) {
                $this->currentProject->clearGenerationState();
            }
            
            Log::info('[CODE_GEN] Generation finished');
            // Hide the code cube loader
            $this->dispatch('hideCodeCubeLoader');
        }
    }
    
    public function createPreview()
    {
        if (empty($this->generatedCode) || !$this->currentProject) {
            Log::warning('[CODE_GEN] Cannot create preview', [
                'has_code' => !empty($this->generatedCode),
                'has_project' => !empty($this->currentProject)
            ]);
            return;
        }
        
        try {
            $dockerService = app(DockerPreviewService::class);
            
            Log::info('[CODE_GEN] Getting or creating Docker container', [
                'project_id' => $this->currentProject->id
            ]);
            
            // Get or create container for the project
            $previewUrl = $dockerService->getOrCreateProjectContainer($this->currentProject);
            
            Log::info('[CODE_GEN] Container ready', ['preview_url' => $previewUrl]);
            
            // Inject the generated code
            Log::info('[CODE_GEN] Injecting code into container', [
                'component_name' => $this->componentName
            ]);
            
            $success = $dockerService->injectCode(
                $this->currentProject,
                $this->generatedCode,
                $this->componentName
            );
            
            Log::info('[CODE_GEN] Code injection result', ['success' => $success]);
            
                    if ($success) {
                        // Get the route for this component from project metadata
                        $routes = $this->currentProject->getRoutes();
                        $componentRoute = '/';
                        foreach ($routes as $route) {
                            if ($route['component'] === $this->componentName) {
                                $componentRoute = $route['url'];
                                break;
                            }
                        }
                        
                        // Build proxy URL (same origin, so iframes work)
                        // Format: /preview/{projectId}/{route}
                        $baseUrl = request()->getSchemeAndHttpHost();
                        $routePath = ltrim($componentRoute, '/'); // Remove leading slash for proxy path
                        $this->previewUrl = "{$baseUrl}/preview/{$this->currentProject->id}" . ($routePath ? "/{$routePath}" : '');
                        $this->selectedRoute = $componentRoute;
                        $this->previewReady = true;
                        $this->isGenerating = false; // Ensure generating flag is cleared

                        // Update generation state with preview URL
                        if ($this->currentProject) {
                            $this->currentProject->setGenerationState([
                                'preview_url' => $this->previewUrl,
                                'preview_ready' => true,
                            ]);
                        }

                        // Load project files
                        $this->loadProjectFiles();
                
                // Auto-select the newly generated file if it exists
                $generatedFilePath = "/var/www/html/app/Livewire/{$this->componentName}.php";
                if (in_array($generatedFilePath, $this->projectFiles)) {
                    $this->selectFile($generatedFilePath);
                    Log::info('[CODE_GEN] Auto-selected generated file', ['file' => $generatedFilePath]);
                } else {
                    // If file not in projectFiles yet, try to read it directly
                    // This can happen if loadProjectFiles() hasn't picked it up yet
                    try {
                        $dockerService = app(DockerPreviewService::class);
                        $fileContent = $dockerService->readFileFromContainer(
                            $this->currentProject->container_id,
                            $generatedFilePath
                        );
                        if (!empty($fileContent)) {
                            $this->generatedCode = $fileContent;
                            $this->selectedFilePath = $generatedFilePath;
                            Log::info('[CODE_GEN] Loaded file directly', ['file' => $generatedFilePath]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('[CODE_GEN] Could not load file directly', ['error' => $e->getMessage()]);
                    }
                }
                
                Log::info('[CODE_GEN] Preview ready', [
                    'preview_url' => $this->previewUrl,
                    'component_route' => $componentRoute,
                    'component_name' => $this->componentName,
                    'is_generating' => $this->isGenerating,
                    'preview_ready' => $this->previewReady,
                    'has_generated_code' => !empty($this->generatedCode),
                    'selected_file' => $this->selectedFilePath
                ]);
                
                // Auto-switch to preview tab when ready
                $this->activeTab = 'preview';
                
                // Dispatch event to update UI
                $this->dispatch('code-generation-complete', [
                    'component_name' => $this->componentName,
                    'preview_url' => $this->previewUrl
                ]);
                
                $this->success('Component generated, validated, and preview ready!');
            } else {
                Log::error('[CODE_GEN] Code injection failed');
                
                // Create error notification for preview failure
                NotificationService::error(
                    'Preview Creation Failed',
                    'Failed to generate component. The code may have validation errors or the container may need to be restarted.',
                    $this->currentProject->id ?? null,
                    ['component_name' => $this->componentName ?? 'Unknown']
                );
                
                // Dispatch event to refresh notifications
                $this->dispatch('notification-created');
                
                $this->error('Failed to generate component. The code may have validation errors or the container may need to be restarted.');
            }
            
        } catch (\Exception $e) {
            Log::error('[CODE_GEN] Exception during preview creation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error('Error creating preview: ' . $e->getMessage());
        }
    }
    
    public function updatePreview()
    {
        $this->createPreview();
    }
    
    public function stopPreview()
    {
        if (!$this->currentProject) {
            return;
        }
        
        try {
            $dockerService = app(DockerPreviewService::class);
            $dockerService->removeContainer($this->currentProject->container_id);
            
            $this->currentProject->update([
                'container_id' => null,
                'container_name' => null,
                'port' => null,
                'preview_url' => null,
                'status' => 'stopped'
            ]);
            
            $this->previewUrl = '';
            $this->previewReady = false;
            $this->success('Preview stopped');
            
        } catch (\Exception $e) {
            $this->error('Error stopping preview: ' . $e->getMessage());
        }
    }
    
    public function handleCodeGenerated($data)
    {
        $this->generatedCode = $data['code'] ?? '';
        $this->componentName = $data['component_name'] ?? 'GeneratedComponent';
        $this->createPreview();
    }
    
    public function handleGenerateCodeRequest($data)
    {
        Log::info('[CODE_GEN] Event received', ['data' => $data]);
        
        // Handle different event data formats
        $prompt = '';
        $targetComponentName = null;
        
        if (is_array($data)) {
            // Direct array format: ['prompt' => '...', 'component_name' => '...']
            if (isset($data['prompt'])) {
                $prompt = $data['prompt'];
                $targetComponentName = $data['component_name'] ?? null;
            }
            // Nested array format: [['prompt' => '...']]
            elseif (isset($data[0]) && is_array($data[0]) && isset($data[0]['prompt'])) {
                $prompt = $data[0]['prompt'];
                $targetComponentName = $data[0]['component_name'] ?? null;
            }
            // Single element array: ['prompt']
            elseif (count($data) === 1 && is_string($data[0])) {
                $prompt = $data[0];
            }
        } elseif (is_string($data)) {
            $prompt = $data;
        }
        
        if ($prompt) {
            Log::info('[CODE_GEN] Starting code generation', [
                'prompt' => $prompt,
                'target_component' => $targetComponentName
            ]);
            $this->generateCode($prompt, $targetComponentName);
        } else {
            Log::warning('[CODE_GEN] No prompt provided in event data', ['data_type' => gettype($data), 'data' => $data]);
        }
    }
    
    private function getOrCreateDefaultProject(): Project
    {
        $user = Auth::user();
        
        // Try to get the most recently accessed project
        $project = Project::where('user_id', $user->id)
                         ->orderBy('last_accessed_at', 'desc')
                         ->orderBy('created_at', 'desc')
                         ->first();
        
        // If no project exists, create a default one
        if (!$project) {
            $project = Project::create([
                'user_id' => $user->id,
                'name' => 'My First Project',
                'description' => 'Default project for code generation',
                'status' => 'creating',
                'metadata' => [
                    'components' => [],
                    'routes' => [],
                    'created_at' => now()->toISOString()
                ]
            ]);
        }
        
        return $project;
    }
    
    private function initializePreview()
    {
        if (!$this->currentProject) {
            return;
        }
        
        try {
            $dockerService = app(DockerPreviewService::class);
            
            // Check if container exists and is running
            if ($this->currentProject->container_id && 
                $dockerService->isContainerRunning($this->currentProject->container_id)) {
                $this->previewUrl = $this->currentProject->preview_url;
                $this->previewReady = true;
            }
            
        } catch (\Exception $e) {
            // Silently fail - preview will be created when needed
        }
    }
    
    public function loadProjectFiles()
    {
        if (!$this->currentProject || !$this->currentProject->container_id) {
            $this->projectFiles = [];
            $this->projectFilesTree = [];
            return;
        }
        
        Log::info('[CODE_GEN] Loading project files from container');
        
        try {
            $dockerService = app(DockerPreviewService::class);
            
            // Get files from resources and app/Http
            $this->projectFiles = $dockerService->listProjectFiles($this->currentProject->container_id);
            
            // Organize files into a tree structure
            $this->projectFilesTree = $this->organizeFilesIntoTree($this->projectFiles);
            
            Log::info('[CODE_GEN] Files loaded', ['count' => count($this->projectFiles)]);
        } catch (\Exception $e) {
            Log::error('[CODE_GEN] Failed to load files', ['error' => $e->getMessage()]);
            $this->projectFiles = [];
            $this->projectFilesTree = [];
        }
    }
    
    /**
     * Organize files into a tree structure by directory.
     */
    private function organizeFilesIntoTree(array $files): array
    {
        $tree = [];
        
        foreach ($files as $file) {
            // Remove /var/www/html prefix
            $relativePath = str_replace('/var/www/html/', '', $file);
            $parts = explode('/', $relativePath);
            
            $current = &$tree;
            $path = '';
            
            // Build nested structure
            for ($i = 0; $i < count($parts) - 1; $i++) {
                $part = $parts[$i];
                $path .= ($path ? '/' : '') . $part;
                
                if (!isset($current[$part])) {
                    $current[$part] = [
                        'type' => 'folder',
                        'path' => '/var/www/html/' . $path,
                        'children' => []
                    ];
                }
                
                $current = &$current[$part]['children'];
            }
            
            // Add file
            $filename = end($parts);
            $current[$filename] = [
                'type' => 'file',
                'path' => $file,
                'name' => $filename
            ];
        }
        
        return $tree;
    }
    
    public function selectFile(string $filePath)
    {
        $this->selectedFilePath = $filePath;
        
        try {
            $dockerService = app(DockerPreviewService::class);
            $this->generatedCode = $dockerService->readFileFromContainer(
                $this->currentProject->container_id,
                $filePath
            );
            
            Log::info('[CODE_GEN] File loaded', ['file' => $filePath]);
        } catch (\Exception $e) {
            Log::error('[CODE_GEN] Failed to read file', ['error' => $e->getMessage()]);
            $this->error('Failed to load file: ' . $e->getMessage());
        }
    }
    
    public function getComponentsProperty(): array
    {
        if (!$this->currentProject) {
            return [];
        }
        return $this->currentProject->getComponents();
    }
    
    /**
     * Check generation status - called by wire:poll during generation
     */
    public function checkGenerationStatus()
    {
        // Only check if we're in generating state
        if (!$this->isGenerating || !$this->currentProject) {
            return;
        }
        
        // Check if generation has completed in the database
        $state = $this->currentProject->getGenerationState();
        
        // If generation completed but we haven't updated UI yet
        if (!empty($state['completed_at']) && !empty($state['generated_code']) && !$this->previewReady) {
            Log::info('[CODE_GEN] Generation completed, updating UI', [
                'project_id' => $this->currentProject->id,
                'component_name' => $state['component_name'] ?? null
            ]);
            
            // Restore the completed state
            $this->restoreGenerationState();
            
            // Ensure preview is shown
            if ($this->previewReady) {
                $this->activeTab = 'preview';
            }
        }
    }
    
    public function render()
    {
        return view('livewire.code-generation-engine');
    }
}
