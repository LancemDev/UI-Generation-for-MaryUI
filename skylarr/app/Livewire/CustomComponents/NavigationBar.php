<?php

namespace App\Livewire\CustomComponents;

use Livewire\Component;
use Mary\Traits\Toast;
use App\Models\Project;
use App\Models\Notification;
use Illuminate\Support\Facades\Auth;

class NavigationBar extends Component
{
    use Toast;

    public $projects = [];
    public $selectedProjectId = null;
    public $selectedProject = null;
    public $notifications = [];
    public $unreadCount = 0;

    public function mount($projects = null, $selectedProjectId = null, $selectedProject = null)
    {
        $this->updateProjectData($projects, $selectedProjectId, $selectedProject);
        $this->loadNotifications();
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
        'notification-created' => 'loadNotifications',
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

    /**
     * Load projects for the current user.
     */
    public function loadProjects()
    {
        if (!Auth::check()) {
            $this->projects = [];
            return;
        }

        $this->projects = Project::where('user_id', Auth::id())
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    /**
     * Load notifications for the current user.
     */
    public function loadNotifications()
    {
        if (!Auth::check()) {
            $this->notifications = [];
            $this->unreadCount = 0;
            return;
        }

        $this->notifications = Notification::where('user_id', Auth::id())
            ->recent(20)
            ->with('project')
            ->get()
            ->toArray();

        $this->unreadCount = Notification::where('user_id', Auth::id())
            ->unread()
            ->count();
    }


    /**
     * Mark notification as read.
     */
    public function markAsRead($notificationId)
    {
        $notification = Notification::where('user_id', Auth::id())
            ->find($notificationId);

        if ($notification) {
            $notification->markAsRead();
            $this->loadNotifications();
        }
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead()
    {
        Notification::where('user_id', Auth::id())
            ->unread()
            ->update([
                'read' => true,
                'read_at' => now(),
            ]);

        $this->loadNotifications();
    }
    
    public function render()
    {
        return view('livewire.custom-components.navigation-bar');
    }
}
