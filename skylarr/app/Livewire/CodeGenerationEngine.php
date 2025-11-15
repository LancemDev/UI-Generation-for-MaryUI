<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Project;
use App\Services\DockerPreviewService;
use App\Services\AiGateway;
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
    public string $selectedFile = '';
    public string $selectedFilePath = '';
    
    protected $listeners = [
        'codeGenerated' => 'handleCodeGenerated',
        'generate-code' => 'handleGenerateCodeRequest',
        'projectChanged' => 'handleProjectChanged'
    ];
    
    public function mount(?int $projectId = null)
    {
        $this->projectId = $projectId;
        
        // Only load project if we have a valid projectId
        if ($this->projectId) {
            $this->loadProject();
            $this->initializePreview();
        }
    }
    
    public function handleProjectChanged($projectData)
    {
        $this->projectId = $projectData['id'];
        $this->loadProject();
        $this->initializePreview();
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
    
    public function generateCode(string $prompt)
    {
        if (!$this->currentProject) {
            Log::error('[CODE_GEN] No project selected');
            $this->error('No project selected');
            return;
        }
        
        Log::info('[CODE_GEN] Starting generation', [
            'project_id' => $this->currentProject->id,
            'prompt' => $prompt
        ]);
        
        $this->isGenerating = true;
        $this->previewReady = false;
        
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
                $this->componentName = $response['component_name'] ?? 'GeneratedComponent';
                
                Log::info('[CODE_GEN] Code generated successfully', [
                    'component_name' => $this->componentName,
                    'code_length' => strlen($this->generatedCode)
                ]);
                
                // Switch to code tab to show the generated code
                $this->activeTab = 'code';
                
                // Create preview
                Log::info('[CODE_GEN] Starting preview creation');
                $this->createPreview();
                
                // Send message to chat interface
                $this->dispatch('code-generation-complete', [
                    'component_name' => $this->componentName,
                    'message' => 'Code generation completed successfully!'
                ]);
                
                $this->success('Code generated successfully!');
            } else {
                Log::error('[CODE_GEN] Code generation failed', ['message' => $response['message']]);
                $this->error('Failed to generate code: ' . $response['message']);
                
                // Send error message to chat interface
                $this->dispatch('code-generation-failed', [
                    'message' => $response['message'] ?? 'Unknown error occurred'
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('[CODE_GEN] Exception during generation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error('Error generating code: ' . $e->getMessage());
            
            // Send error message to chat interface
            $this->dispatch('code-generation-failed', [
                'message' => $e->getMessage()
            ]);
        } finally {
            $this->isGenerating = false;
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
                $this->previewUrl = $previewUrl;
                $this->previewReady = true;
                
                // Load project files
                $this->loadProjectFiles();
                
                // Auto-select the newly generated file if it exists
                $generatedFilePath = "/var/www/html/app/Livewire/{$this->componentName}.php";
                if (in_array($generatedFilePath, $this->projectFiles)) {
                    $this->selectFile($generatedFilePath);
                    Log::info('[CODE_GEN] Auto-selected generated file', ['file' => $generatedFilePath]);
                }
                
                Log::info('[CODE_GEN] Preview ready', ['preview_url' => $this->previewUrl]);
                $this->success('Preview updated!');
            } else {
                Log::error('[CODE_GEN] Code injection failed');
                $this->error('Failed to update preview');
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
        
        if (is_array($data)) {
            // Direct array format: ['prompt' => '...']
            if (isset($data['prompt'])) {
                $prompt = $data['prompt'];
            }
            // Nested array format: [['prompt' => '...']]
            elseif (isset($data[0]) && is_array($data[0]) && isset($data[0]['prompt'])) {
                $prompt = $data[0]['prompt'];
            }
            // Single element array: ['prompt']
            elseif (count($data) === 1 && is_string($data[0])) {
                $prompt = $data[0];
            }
        } elseif (is_string($data)) {
            $prompt = $data;
        }
        
        if ($prompt) {
            Log::info('[CODE_GEN] Starting code generation', ['prompt' => $prompt]);
            $this->generateCode($prompt);
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
            return;
        }
        
        Log::info('[CODE_GEN] Loading project files from container');
        
        try {
            $dockerService = app(DockerPreviewService::class);
            
            // Get files from resources and app/Http
            $this->projectFiles = $dockerService->listProjectFiles($this->currentProject->container_id);
            
            Log::info('[CODE_GEN] Files loaded', ['count' => count($this->projectFiles)]);
        } catch (\Exception $e) {
            Log::error('[CODE_GEN] Failed to load files', ['error' => $e->getMessage()]);
            $this->projectFiles = [];
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
    
    public function render()
    {
        return view('livewire.code-generation-engine');
    }
}
