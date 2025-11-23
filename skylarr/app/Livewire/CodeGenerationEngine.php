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
    public string $selectedTheme = 'light';
    public bool $showComponentSelectModal = false;
    public bool $showOverwriteConfirmModal = false;
    public ?string $pendingComponentName = null;
    public string $pendingPrompt = '';
    public array $pendingConversationHistory = [];
    
    // Available daisyUI themes (as simple array for internal use)
    private array $themeList = [
        'light', 'dark', 'cupcake', 'bumblebee', 'emerald', 'corporate', 'synthwave', 
        'retro', 'cyberpunk', 'valentine', 'halloween', 'garden', 'forest', 'aqua', 
        'lofi', 'pastel', 'fantasy', 'wireframe', 'black', 'luxury', 'dracula', 
        'cmyk', 'autumn', 'business', 'acid', 'lemonade', 'night', 'coffee', 
        'winter', 'dim', 'nord', 'sunset', 'caramellatte', 'abyss', 'silk'
    ];
    
    /**
     * Themes formatted for MaryUI select component.
     * MaryUI expects an array of objects with 'id' and 'name' properties.
     */
    public array $availableThemes = [];
    
    protected $listeners = [
        'codeGenerated' => 'handleCodeGenerated',
        'generate-code' => 'handleGenerateCodeRequest',
        'projectChanged' => 'handleProjectChanged'
    ];
    
    public function mount(?int $projectId = null)
    {
        $this->projectId = $projectId;
        
        // Initialize available themes formatted for MaryUI select
        // MaryUI expects objects with 'id' and 'name' properties
        $this->availableThemes = collect($this->themeList)
            ->map(fn($theme) => [
                'id' => $theme,
                'name' => ucfirst($theme)
            ])
            ->values()
            ->toArray();
        
        // Only load project if we have a valid projectId
        if ($this->projectId) {
            $this->loadProject();
            $this->initializePreview();
            $this->restoreGenerationState();
        }
    }

    /**
     * Restore generation state from database if generation was in progress or completed.
     */
    private function restoreGenerationState(): void
    {
        if (!$this->currentProject) {
            return;
        }
        
        // Restore selected theme from project metadata if available
        $metadata = $this->currentProject->metadata ?? [];
        if (isset($metadata['selected_theme']) && in_array($metadata['selected_theme'], $this->themeList)) {
            $this->selectedTheme = $metadata['selected_theme'];
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
                                    $baseUrl = parse_url($this->previewUrl, PHP_URL_SCHEME) . '://' . parse_url($this->previewUrl, PHP_URL_HOST) . ':' . parse_url($this->previewUrl, PHP_URL_PORT);
                                    $this->previewUrl = $baseUrl . $route['url'];
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
        $this->projectId = $projectData['id'];
        $this->loadProject();
        $this->initializePreview();
        $this->restoreGenerationState();
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
    
    public function generateCode(string $prompt, ?string $targetComponentName = null, array $conversationHistory = [])
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
                $this->pendingConversationHistory = $conversationHistory;
                $this->showOverwriteConfirmModal = true;
                return;
            }
        }
        
        $this->doGenerateCode($prompt, $targetComponentName, $conversationHistory);
    }
    
    public function confirmOverwrite(): void
    {
        if ($this->pendingComponentName && $this->pendingPrompt) {
            // Store values before clearing
            $prompt = $this->pendingPrompt;
            $componentName = $this->pendingComponentName;
            $conversationHistory = $this->pendingConversationHistory;
            
            // Close modal first and clear pending state
            $this->showOverwriteConfirmModal = false;
            $this->pendingComponentName = null;
            $this->pendingPrompt = '';
            $this->pendingConversationHistory = [];
            
            // Use JavaScript to ensure modal closes, then start generation after a brief delay
            // This allows the modal's wire:model to update before we start the generation process
            $this->js("
                setTimeout(() => {
                    \$wire.doGenerateCodeDeferred(" . json_encode($prompt) . ", " . json_encode($componentName) . ", " . json_encode($conversationHistory) . ");
                }, 200);
            ");
        }
    }
    
    public function doGenerateCodeDeferred(string $prompt, ?string $targetComponentName = null, array $conversationHistory = []): void
    {
        $this->doGenerateCode($prompt, $targetComponentName, $conversationHistory);
    }
    
    public function cancelOverwrite(): void
    {
        $this->showOverwriteConfirmModal = false;
        $this->pendingComponentName = null;
        $this->pendingPrompt = '';
        $this->pendingConversationHistory = [];
        
        // Force Livewire to update the modal state
        $this->dispatch('$refresh');
    }
    
    private function doGenerateCode(string $prompt, ?string $targetComponentName = null, array $conversationHistory = []): void
    {
        // Extract component name from conversation history if not provided (for follow-up requests)
        if (!$targetComponentName && !empty($conversationHistory)) {
            foreach (array_reverse($conversationHistory) as $msg) {
                if ($msg['role'] === 'assistant' && isset($msg['content'])) {
                    // Try to extract component name from messages like "I've created the `ComponentName` component"
                    if (preg_match("/`([A-Z][a-zA-Z0-9]+)`/", $msg['content'], $matches)) {
                        $targetComponentName = $matches[1];
                        Log::info('[CODE_GEN] Extracted component name from conversation history', [
                            'component_name' => $targetComponentName
                        ]);
                        break;
                    }
                    // Also check for component names like "RegisterForm", "LoginForm", etc.
                    if (preg_match("/([A-Z][a-zA-Z0-9]+Form|[A-Z][a-zA-Z0-9]+Component|[A-Z][a-zA-Z0-9]+Modal|[A-Z][a-zA-Z0-9]+Dashboard)/", $msg['content'], $matches)) {
                        $targetComponentName = $matches[1];
                        Log::info('[CODE_GEN] Extracted component name from conversation history', [
                            'component_name' => $targetComponentName
                        ]);
                        break;
                    }
                }
            }
        }
        
        Log::info('[CODE_GEN] Starting generation', [
            'project_id' => $this->currentProject->id,
            'prompt' => $prompt,
            'target_component' => $targetComponentName,
            'history_count' => count($conversationHistory)
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
            
            Log::info('[CODE_GEN] Calling AI Gateway', [
                'history_count' => count($conversationHistory)
            ]);
            $response = $aiGateway->generateCode($prompt, $conversationHistory);
            
            Log::info('[CODE_GEN] AI Gateway response received', [
                'success' => $response['success'] ?? false,
                'has_code' => isset($response['code'])
            ]);
            
            if ($response['success']) {
                $this->generatedCode = $response['code'];
                // Use target component name if provided, otherwise use AI-generated name
                // For follow-ups, prefer the extracted name to ensure we update the same component
                $this->componentName = $targetComponentName ?? $response['component_name'] ?? 'GeneratedComponent';
                
                Log::info('[CODE_GEN] Code generated successfully', [
                    'component_name' => $this->componentName,
                    'code_length' => strlen($this->generatedCode)
                ]);
                
                // Switch to code tab to show the generated code
                $this->activeTab = 'preview';
                
                // Create preview (this will save the completed state internally)
                Log::info('[CODE_GEN] Starting preview creation');
                $this->createPreview();
                
                // Note: completeGeneration() is now called inside createPreview() after success
                
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
                
                // Load project files to show the new component
                $this->loadProjectFiles();
                
                // Auto-select the generated file
                if ($this->componentName) {
                    $generatedFilePath = "/var/www/html/app/Livewire/{$this->componentName}.php";
                    if (in_array($generatedFilePath, $this->projectFiles)) {
                        $this->selectFile($generatedFilePath);
                    }
                }
                
                // Update selected route FIRST before refreshing iframe
                $routes = $this->currentProject->getRoutes();
                foreach ($routes as $route) {
                    if ($route['component'] === $this->componentName) {
                        $this->selectedRoute = $route['url'];
                        $baseUrl = parse_url($this->previewUrl, PHP_URL_SCHEME) . '://' . parse_url($this->previewUrl, PHP_URL_HOST) . ':' . parse_url($this->previewUrl, PHP_URL_PORT);
                        $this->previewUrl = $baseUrl . $route['url'];
                        break;
                    }
                }
                
                // Switch to preview tab to show the result
                $this->activeTab = 'preview';
                
                // Force UI refresh to show the new code and preview
                $this->dispatch('$refresh');
                
                // Refresh iframe AFTER route is set, with a longer delay to ensure route is ready
                $finalPreviewUrl = $this->previewUrl;
                $this->js("
                    setTimeout(() => {
                        const iframe = document.getElementById('preview-iframe');
                        if (iframe && '{$finalPreviewUrl}') {
                            iframe.src = '{$finalPreviewUrl}';
                        }
                    }, 1000);
                ");
                
                Log::info('[CODE_GEN] Success events dispatched', [
                    'component_name' => $this->componentName,
                    'is_generating' => $this->isGenerating,
                    'preview_ready' => $this->previewReady
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
            
            // Apply selected theme to the container
            if ($this->currentProject->container_id && $this->selectedTheme) {
                try {
                    $dockerService->updatePreviewTheme($this->currentProject->container_id, $this->selectedTheme);
                    Log::info('[CODE_GEN] Theme applied to container', ['theme' => $this->selectedTheme]);
                } catch (\Exception $e) {
                    Log::warning('[CODE_GEN] Failed to apply theme to container', [
                        'theme' => $this->selectedTheme,
                        'error' => $e->getMessage()
                    ]);
                    // Don't fail the whole process if theme update fails
                }
            }
            
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
                        $this->previewReady = true;
                        $this->isGenerating = false; // Ensure generating flag is cleared

                        // Load project files first
                        $this->loadProjectFiles();
                
                        // Auto-select the newly generated file if it exists
                        $generatedFilePath = "/var/www/html/app/Livewire/{$this->componentName}.php";
                        if (in_array($generatedFilePath, $this->projectFiles)) {
                            $this->selectFile($generatedFilePath);
                            Log::info('[CODE_GEN] Auto-selected generated file', ['file' => $generatedFilePath]);
                        }
                        
                        // Set the route and build full preview URL BEFORE setting previewUrl
                        $routes = $this->currentProject->getRoutes();
                        $fullPreviewUrl = $previewUrl; // Default to base URL
                        foreach ($routes as $route) {
                            if ($route['component'] === $this->componentName) {
                                $this->selectedRoute = $route['url'];
                                $baseUrl = parse_url($previewUrl, PHP_URL_SCHEME) . '://' . parse_url($previewUrl, PHP_URL_HOST) . ':' . parse_url($previewUrl, PHP_URL_PORT);
                                $fullPreviewUrl = $baseUrl . $route['url'];
                                break;
                            }
                        }
                        
                        // Now set the full preview URL with route included
                        $this->previewUrl = $fullPreviewUrl;

                        // Save completed generation state to database (with all data)
                        if ($this->currentProject) {
                            $this->currentProject->completeGeneration(
                                $this->componentName,
                                $this->generatedCode,
                                $fullPreviewUrl
                            );
                            // Also set preview_ready flag
                            $this->currentProject->setGenerationState([
                                'preview_ready' => true,
                            ]);
                        }
                
                Log::info('[CODE_GEN] Preview ready', [
                    'preview_url' => $this->previewUrl,
                    'component_name' => $this->componentName,
                    'is_generating' => $this->isGenerating,
                    'preview_ready' => $this->previewReady,
                    'selected_route' => $this->selectedRoute
                ]);
                
                // Switch to preview tab
                $this->activeTab = 'preview';
                
                // Force Livewire to update - ensure all state is set first
                // Trigger a property update to force re-render
                $this->dispatch('$refresh');
                
                // Use JavaScript to ensure UI updates and iframe loads
                $finalPreviewUrl = $this->previewUrl;
                $componentId = $this->getId();
                $this->js("
                    // Small delay to ensure Livewire has processed the state changes
                    setTimeout(() => {
                        // Force Livewire component to refresh
                        const component = Livewire.find('{$componentId}');
                        if (component) {
                            component.\$refresh();
                        }
                        
                        // Wait a bit more for route to be ready, then update iframe
                        setTimeout(() => {
                            const iframe = document.getElementById('preview-iframe');
                            if (iframe && '{$finalPreviewUrl}') {
                                iframe.src = '{$finalPreviewUrl}';
                            }
                        }, 1000);
                    }, 200);
                ");
                
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
    
    public function updatedSelectedTheme($theme)
    {
        if (!$this->currentProject) {
            return;
        }
        
        // Save theme to project metadata
        $metadata = $this->currentProject->metadata ?? [];
        $metadata['selected_theme'] = $theme;
        $this->currentProject->update(['metadata' => $metadata]);
        
        // Update theme in container if it exists
        if ($this->currentProject->container_id) {
            try {
                $dockerService = app(DockerPreviewService::class);
                $dockerService->updatePreviewTheme($this->currentProject->container_id, $theme);
                
                // Refresh the iframe to show the new theme
                $this->js("
                    const iframe = document.getElementById('preview-iframe');
                    if (iframe) {
                        iframe.src = iframe.src;
                    }
                ");
                
                Log::info('[CODE_GEN] Theme updated', ['theme' => $theme]);
            } catch (\Exception $e) {
                Log::error('[CODE_GEN] Failed to update theme', [
                    'theme' => $theme,
                    'error' => $e->getMessage()
                ]);
                $this->error('Failed to update theme: ' . $e->getMessage());
            }
        }
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
        $conversationHistory = [];
        
        if (is_array($data)) {
            // Direct array format: ['prompt' => '...', 'component_name' => '...', 'conversation_history' => [...]]
            if (isset($data['prompt'])) {
                $prompt = $data['prompt'];
                $targetComponentName = $data['component_name'] ?? null;
                $conversationHistory = $data['conversation_history'] ?? [];
            }
            // Nested array format: [['prompt' => '...']]
            elseif (isset($data[0]) && is_array($data[0]) && isset($data[0]['prompt'])) {
                $prompt = $data[0]['prompt'];
                $targetComponentName = $data[0]['component_name'] ?? null;
                $conversationHistory = $data[0]['conversation_history'] ?? [];
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
                'target_component' => $targetComponentName,
                'history_count' => count($conversationHistory)
            ]);
            $this->generateCode($prompt, $targetComponentName, $conversationHistory);
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
    
    /**
     * Check generation status and update UI if generation completed.
     * Called by wire:poll continuously to check for completion.
     */
    public function checkGenerationStatus(): void
    {
        if (!$this->currentProject) {
            return;
        }
        
        // Refresh the project model to get latest state from database
        $this->currentProject->refresh();
        
        // Check if generation completed in database state
        $state = $this->currentProject->getGenerationState();
        $isGeneratingInState = $state['is_generating'] ?? false;
        $hasCompleted = !empty($state['completed_at']) && !empty($state['generated_code']);
        
        // If state shows completion but UI hasn't updated yet, update UI
        if ($hasCompleted && ($this->isGenerating || empty($this->generatedCode) || empty($this->previewUrl))) {
            Log::info('[CODE_GEN] Polling detected completion, updating UI', [
                'component_name' => $state['component_name'] ?? null,
                'has_preview_url' => !empty($state['preview_url']),
                'preview_ready' => !empty($state['preview_ready'])
            ]);
            
            // Generation completed - update UI
            $this->isGenerating = false;
            $this->componentName = $state['component_name'] ?? '';
            $this->generatedCode = $state['generated_code'] ?? '';
            $this->previewUrl = $state['preview_url'] ?? '';
            $this->previewReady = !empty($state['preview_ready']);
            $this->activeTab = 'preview';
            
            // Load project files and select the generated file
            $this->loadProjectFiles();
            if ($this->componentName) {
                $generatedFilePath = "/var/www/html/app/Livewire/{$this->componentName}.php";
                if (in_array($generatedFilePath, $this->projectFiles)) {
                    $this->selectFile($generatedFilePath);
                }
            }
            
            // Update route and build full preview URL
            $routes = $this->currentProject->getRoutes();
            foreach ($routes as $route) {
                if ($route['component'] === $this->componentName) {
                    $this->selectedRoute = $route['url'];
                    if ($this->previewUrl) {
                        $baseUrl = parse_url($this->previewUrl, PHP_URL_SCHEME) . '://' . 
                                   parse_url($this->previewUrl, PHP_URL_HOST) . ':' . 
                                   parse_url($this->previewUrl, PHP_URL_PORT);
                        $this->previewUrl = $baseUrl . $route['url'];
                    }
                    break;
                }
            }
            
            // Force UI refresh
            $this->dispatch('$refresh');
        }
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
    
    public function render()
    {
        return view('livewire.code-generation-engine');
    }
}
