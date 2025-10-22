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
    public string $projectName, $projectDescription;
    public $projects, $selectedProjectId;

    public function openCreateProjectModal()
    {
        $this->createNewProjectModal = true;
    }

    public function submitProjectCreation()
    {
        $this->selectedProjectId = $this->projects->first()->id;
        $this->projectSelectionModal = false;
        $this->success('Project selected successfully');
    }

    public function mount()
    {
        $this->projects = Project::where('user_id', Auth::id())->get();
        if ($this->projects->count() > 0) {
            $this->success('Please create a project to get started');
            $this->projectSelectionModal = false;
            $this->projectSelectionModal = true;
        }
    }

    public function createProject()
    {
        $this->validate([
            'projectName' => 'required|string|max:255',
            'projectDescription' => 'nullable|string|max:1000',
        ]);

        Project::create([
            'user_id' => Auth::id(),
            'name' => $this->projectName,
            'description' => $this->projectDescription,
        ]);

        $this->projectSelectionModal = false;
        $this->createNewProjectModal = false;
        $this->projectName = '';
        $this->projectDescription = '';
        $this->success('Project created successfully');
        $this->createNewProjectModal = false;
        $this->projectSelectionModal = true;
    }
    
    public function render()
    {
        return view('livewire.code-generator',
        [
            'projects' => $this->projects
        ]
    );
    }
}
