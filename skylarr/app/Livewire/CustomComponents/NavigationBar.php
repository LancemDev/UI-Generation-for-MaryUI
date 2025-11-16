<?php

namespace App\Livewire\CustomComponents;

use Livewire\Component;
use Mary\Traits\Toast;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;

class NavigationBar extends Component
{
    use Toast;

    public $projects = [];
    public $selectedProjectId = null;
    public $selectedProject = null;

    public function mount($projects = null, $selectedProjectId = null, $selectedProject = null)
    {
        $this->updateProjectData($projects, $selectedProjectId, $selectedProject);
    }

    /**
     * Update project data when parent component updates.
     */
    public function updateProjectData($projects = null, $selectedProjectId = null, $selectedProject = null)
    {
        $this->projects = $projects ?? [];
        $this->selectedProjectId = $selectedProjectId;
        $this->selectedProject = $selectedProject;
    }

    protected $listeners = [
        'project-updated' => 'refreshProjectData',
    ];

    /**
     * Refresh project data when parent component updates.
     */
    public function refreshProjectData($data)
    {
        $selectedProjectId = $data['selectedProjectId'] ?? null;
        
        if ($selectedProjectId) {
            // Fetch the project from database
            $selectedProject = Project::where('user_id', Auth::id())
                ->find($selectedProjectId);
            
            // Refresh projects list
            $projects = Project::where('user_id', Auth::id())
                ->orderBy('updated_at', 'desc')
                ->get();
            
            $this->updateProjectData($projects, $selectedProjectId, $selectedProject);
        }
    }

    public function switchProject($projectId)
    {
        $this->dispatch('project-switched', $projectId);
    }

    public function openCreateProjectModal()
    {
        $this->dispatch('open-create-project-modal');
    }

    public function openProjectSelection()
    {
        $this->dispatch('open-project-selection');
    }

    public function logout()
    {
        Auth::logout();
        $this->success('Logged out successfully');
        return redirect()->route('welcome');
    }

    public function settings()
    {
        return redirect()->route('settings');
    }
    
    public function render()
    {
        return view('livewire.custom-components.navigation-bar');
    }
}
