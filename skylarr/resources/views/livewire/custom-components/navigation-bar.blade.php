<div>
<style>
    [x-cloak] { display: none !important; }
    
    /* Make project dropdown scrollable */
    .project-dropdown-menu {
        max-height: 24rem !important; /* 96 * 0.25rem = 24rem */
        overflow-y: auto !important;
        overflow-x: hidden !important;
        position: relative !important;
        z-index: 50 !important;
    }
    
    /* Target MaryUI dropdown menu container */
    .project-dropdown [role="menu"],
    .project-dropdown .dropdown-content,
    .project-dropdown ul[role="menu"] {
        max-height: 24rem !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        position: relative !important;
    }
    
    /* Ensure smooth scrolling */
    .project-dropdown-menu::-webkit-scrollbar,
    .project-dropdown [role="menu"]::-webkit-scrollbar,
    .project-dropdown .dropdown-content::-webkit-scrollbar {
        width: 6px;
    }
    
    .project-dropdown-menu::-webkit-scrollbar-track,
    .project-dropdown [role="menu"]::-webkit-scrollbar-track,
    .project-dropdown .dropdown-content::-webkit-scrollbar-track {
        background: transparent;
    }
    
    .project-dropdown-menu::-webkit-scrollbar-thumb,
    .project-dropdown [role="menu"]::-webkit-scrollbar-thumb,
    .project-dropdown .dropdown-content::-webkit-scrollbar-thumb {
        background: rgba(0, 0, 0, 0.2);
        border-radius: 3px;
    }
    
    .project-dropdown-menu::-webkit-scrollbar-thumb:hover,
    .project-dropdown [role="menu"]::-webkit-scrollbar-thumb:hover,
    .project-dropdown .dropdown-content::-webkit-scrollbar-thumb:hover {
        background: rgba(0, 0, 0, 0.3);
    }
</style>
<x-nav sticky full-width class="bg-primary/90 text-base-100 border-b border-secondary/25 backdrop-blur supports-[backdrop-filter]:bg-primary/60">
 
 <x-slot:brand>
     {{-- Drawer toggle for "main-drawer" --}}
    <label for="main-drawer" class="lg:hidden mr-3 text-base-100/90 hover:text-base-100">
        <x-icon name="o-bars-3" class="cursor-pointer" />
     </label>

     {{-- Brand --}}
    <div class="font-semibold tracking-tight">
        SKYLARR
    </div>
 </x-slot:brand>

 {{-- Right side actions --}}
 <x-slot:actions>
    {{-- Project Selector Dropdown --}}
    @if($selectedProject)
        <x-dropdown 
            class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary project-dropdown" 
            icon="o-folder"
            :label="$selectedProject->name"
            right
            wire:poll.5s="loadProjects"
        >
            <div class="project-dropdown-menu" style="max-height: 24rem; overflow-y: auto;">
                <x-menu-item 
                    title="Create New Project" 
                    icon="o-plus" 
                    class="text-blue-600 dark:text-blue-400"
                    wire:click.stop="openCreateProjectModal"
                />
                @if($projects && count($projects) > 0)
                    <x-menu-item separator />
                    @foreach($projects as $project)
                        <x-menu-item 
                            :title="$project->name"
                            :subtitle="$project->description ? \Illuminate\Support\Str::limit($project->description, 40) : null"
                            :icon="$selectedProjectId == $project->id ? 'o-check-circle' : 'o-folder'"
                            :class="$selectedProjectId == $project->id ? 'text-blue-600 dark:text-blue-400 font-semibold' : 'text-gray-700 dark:text-gray-300'"
                            wire:click.stop="switchProject({{ $project->id }})"
                        />
                    @endforeach
                @endif
            </div>
        </x-dropdown>
    @else
        <x-button 
            wire:click="openProjectSelection" 
            icon="o-folder-open" 
            class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary" 
            responsive
        />
    @endif

    <x-theme-toggle />
    
    {{-- Notifications Dropdown --}}
    <div class="relative">
        <x-dropdown 
            class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary" 
            icon="o-bell"
            right
            wire:poll.5s="loadNotifications"
            label="Notifications"
        >
            @if($unreadCount > 0)
            <x-menu-item 
                title="Mark all as read" 
                icon="o-check-circle"
                class="text-gray-700 dark:text-gray-300"
                wire:click.stop="markAllAsRead"
            />
                <x-menu-item separator />
            @endif
            
            @if(count($notifications) > 0)
                @foreach($notifications as $notification)
                    @php
                        $isUnread = !($notification['read'] ?? false);
                        $type = $notification['type'] ?? 'info';
                        $typeIcons = [
                            'success' => 'o-check-circle',
                            'error' => 'o-exclamation-circle',
                            'warning' => 'o-exclamation-triangle',
                            'info' => 'o-information-circle',
                        ];
                        $typeIcon = $typeIcons[$type] ?? 'o-information-circle';
                    @endphp
                    <x-menu-item 
                        :title="$notification['title'] ?? 'Notification'"
                        :subtitle="($notification['message'] ?? '') . (isset($notification['created_at']) ? ' • ' . \Carbon\Carbon::parse($notification['created_at'])->diffForHumans() : '')"
                        :icon="$typeIcon"
                        :class="$isUnread ? 'font-semibold text-gray-900 dark:text-gray-100' : 'text-gray-700 dark:text-gray-300'"
                        wire:click.stop="markAsRead({{ $notification['id'] }})"
                    />
                @endforeach
            @else
                <x-menu-item 
                    title="No notifications" 
                    icon="o-bell-slash"
                    class="text-gray-400"
                />
            @endif
        </x-dropdown>
        @if($unreadCount > 0)
            <span class="absolute -top-1 -right-1 badge badge-error badge-sm">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
        @endif
    </div>
    <x-dropdown class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary" icon="o-user" right label="Profile">
        <x-menu-item title="Logout" icon="o-power" class="text-red-600 dark:text-red-400" wire:click.stop="logout" spinner="logout" />
        <x-menu-item title="Settings" icon="o-cog-6-tooth" class="text-blue-600 dark:text-blue-400" wire:click.stop="settings" spinner="settings" />
    </x-dropdown>
 </x-slot:actions>
</x-nav>
</div>