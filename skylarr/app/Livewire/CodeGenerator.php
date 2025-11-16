<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Mary\Traits\Toast;

class CodeGenerator extends Component
{
    use Toast;
    
    public bool $projectSelectionModal = true;
    public bool $createNewProjectModal = false;
    public string $projectName = '';
    public string $projectDescription = '';
    public $projects;
    public $selectedProjectId;
    public ?Project $selectedProject = null;

    public function openCreateProjectModal()
    {
        $this->createNewProjectModal = true;
    }

    public function submitProjectCreation()
    {
        if ($this->projects->count() > 0) {
            $this->selectedProject = $this->projects->first();
            $this->selectedProjectId = $this->selectedProject->id;
            $this->projectSelectionModal = false;
            $this->success('Project selected successfully');
        }
    }

    public function mount()
    {
        $this->loadProjects();
        
        if ($this->projects->count() > 0) {
            // Auto-select the first project if projects exist
            $this->selectedProject = $this->projects->first();
            $this->selectedProjectId = $this->selectedProject->id;
            $this->projectSelectionModal = false;
        } else {
            // Show project creation modal if no projects exist
            $this->projectSelectionModal = true;
        }
    }
    
    /**
     * Load projects for the current user.
     */
    public function loadProjects()
    {
        $this->projects = Project::where('user_id', Auth::id())
            ->orderBy('updated_at', 'desc')
            ->get();
    }
    
    /**
     * Switch to a different project.
     */
    public function switchProject($projectId)
    {
        $project = Project::where('user_id', Auth::id())
            ->find($projectId);
        
        if ($project) {
            $this->selectedProject = $project;
            $this->selectedProjectId = $project->id;
            $this->projectSelectionModal = false;
            $this->success("Switched to project: {$project->name}");
        } else {
            $this->error('Project not found');
        }
    }
    
    /**
     * Open project selection modal.
     */
    public function openProjectSelection()
    {
        $this->loadProjects();
        $this->projectSelectionModal = true;
    }

    public function createProject()
    {
        $this->validate([
            'projectName' => 'required|string|max:255',
            'projectDescription' => 'nullable|string|max:1000',
        ]);

        $project = Project::create([
            'user_id' => Auth::id(),
            'name' => $this->projectName,
            'description' => $this->projectDescription,
            'status' => 'creating',
            'metadata' => [
                'components' => [],
                'routes' => [],
                'created_at' => now()->toISOString()
            ]
        ]);

        // Set the newly created project as selected
        $this->selectedProject = $project;
        $this->selectedProjectId = $project->id;
        
        // Close modals and reset form
        $this->projectSelectionModal = false;
        $this->createNewProjectModal = false;
        $this->projectName = '';
        $this->projectDescription = '';
        
        // Refresh projects list
        $this->loadProjects();
        
        $this->success('Project created successfully');
    }
    
    public function render()
    {
        return view('livewire.code-generator');
    }
}
