<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Project;
use App\Services\DockerPreviewService;
use App\Services\AiGateway;
use Illuminate\Support\Facades\Auth;
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
    public string $activeTab = 'code';
    
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
            $this->error('No project selected');
            return;
        }
        
        $this->isGenerating = true;
        $this->previewReady = false;
        
        // Show the code cube loader
        $this->dispatch('showCodeCubeLoader');
        
        try {
            // Generate code using AI
            $aiGateway = app(AiGateway::class);
            $response = $aiGateway->generateCode($prompt);
            
            if ($response['success']) {
                $this->generatedCode = $response['code'];
                $this->componentName = $response['component_name'] ?? 'GeneratedComponent';
                
                // Create preview
                $this->createPreview();
                
                $this->success('Code generated successfully!');
            } else {
                $this->error('Failed to generate code: ' . $response['message']);
            }
            
        } catch (\Exception $e) {
            $this->error('Error generating code: ' . $e->getMessage());
        } finally {
            $this->isGenerating = false;
            // Hide the code cube loader
            $this->dispatch('hideCodeCubeLoader');
        }
    }
    
    public function createPreview()
    {
        if (empty($this->generatedCode) || !$this->currentProject) {
            return;
        }
        
        try {
            $dockerService = app(DockerPreviewService::class);
            
            // Get or create container for the project
            $previewUrl = $dockerService->getOrCreateProjectContainer($this->currentProject);
            
            // Inject the generated code
            $success = $dockerService->injectCode(
                $this->currentProject,
                $this->generatedCode,
                $this->componentName
            );
            
            if ($success) {
                $this->previewUrl = $previewUrl;
                $this->previewReady = true;
                $this->success('Preview updated!');
            } else {
                $this->error('Failed to update preview');
            }
            
        } catch (\Exception $e) {
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
        $prompt = $data['prompt'] ?? '';
        if ($prompt) {
            $this->generateCode($prompt);
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
    
    public function render()
    {
        return view('livewire.code-generation-engine');
    }
}
