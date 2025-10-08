<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Mary\Traits\Toast;

class CodeGenerator extends Component
{
    use Toast;
    
    public bool $showProjectModal = false;
    public ?Project $selectedProject = null;
    public array $projects = [];
    public string $newProjectName = '';
    public string $newProjectDescription = '';
    public bool $isCreatingProject = false;
    
    protected $listeners = [
        'projectSelected' => 'selectProject',
        'projectCreated' => 'refreshProjects',
        'refreshProjects' => 'loadProjects'
    ];
    
    public function mount()
    {
        $this->loadProjects();
        $this->selectDefaultProject();
    }
    
    public function loadProjects()
    {
        $this->projects = Project::where('user_id', Auth::id())
            ->orderBy('last_accessed_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }
    
    public function selectDefaultProject()
    {
        if (empty($this->projects)) {
            $this->showProjectModal = true;
            return;
        }
        
        // Select the most recently accessed project
        $this->selectedProject = Project::find($this->projects[0]['id']);
        $this->updateLastAccessed();
    }
    
    public function selectProject($projectId)
    {
        $project = Project::where('user_id', Auth::id())->find($projectId);
        if ($project) {
            $this->selectedProject = $project;
            $this->showProjectModal = false;
            $this->updateLastAccessed();
            $this->dispatch('projectChanged', $project->toArray());
        }
    }
    
    public function createProject()
    {
        $this->validate([
            'newProjectName' => 'required|string|max:255',
            'newProjectDescription' => 'nullable|string|max:1000'
        ]);
        
        $this->isCreatingProject = true;
        
        try {
            $project = Project::create([
                'user_id' => Auth::id(),
                'name' => $this->newProjectName,
                'description' => $this->newProjectDescription,
                'status' => 'creating',
                'metadata' => [
                    'components' => [],
                    'routes' => [],
                    'created_at' => now()->toISOString()
                ]
            ]);
            
            $this->selectedProject = $project;
            $this->showProjectModal = false;
            $this->newProjectName = '';
            $this->newProjectDescription = '';
            $this->loadProjects();
            $this->dispatch('projectChanged', $project->toArray());
            $this->success('Project created successfully!');
            
        } catch (\Exception $e) {
            $this->error('Failed to create project: ' . $e->getMessage());
        } finally {
            $this->isCreatingProject = false;
        }
    }
    
    public function deleteProject($projectId)
    {
        $project = Project::where('user_id', Auth::id())->find($projectId);
        if ($project) {
            $project->delete();
            $this->loadProjects();
            
            // If we deleted the currently selected project, select another one
            if ($this->selectedProject && $this->selectedProject->id == $projectId) {
                if (empty($this->projects)) {
                    $this->selectedProject = null;
                    $this->showProjectModal = true;
                } else {
                    $this->selectProject($this->projects[0]['id']);
                }
            }
            
            $this->success('Project deleted successfully!');
        }
    }
    
    public function openProjectModal()
    {
        $this->showProjectModal = true;
    }
    
    public function closeProjectModal()
    {
        $this->showProjectModal = false;
        $this->newProjectName = '';
        $this->newProjectDescription = '';
    }
    
    private function updateLastAccessed()
    {
        if ($this->selectedProject) {
            $this->selectedProject->update(['last_accessed_at' => now()]);
        }
    }
    
    public function render()
    {
        return view('livewire.code-generator');
    }
}
