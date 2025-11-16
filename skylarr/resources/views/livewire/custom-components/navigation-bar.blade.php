<div>
<style>
    [x-cloak] { display: none !important; }
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
        <div class="relative" x-data="{ open: false }">
            <x-button
                @click="open = !open"
                class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary"
                label="{{ $selectedProject->name }}"
                icon="o-folder"
            />
            
            {{-- Dropdown Menu --}}
            <div
                x-show="open"
                @click.away="open = false"
                x-cloak
                class="absolute right-0 mt-2 w-64 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 z-50 max-h-96 overflow-y-auto"
            >
                <div class="p-2">
                    <div class="px-3 py-2 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 mb-2">
                        Projects ({{ count($projects) }})
                    </div>
                    
                    @if($projects && count($projects) > 0)
                        <div class="space-y-1">
                            @foreach($projects as $project)
                                <button
                                    wire:click="switchProject({{ $project->id }})"
                                    @click="open = false"
                                    class="w-full text-left px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors {{ $selectedProjectId == $project->id ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-900 dark:text-blue-300' : 'text-gray-700 dark:text-gray-300' }}"
                                >
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1 min-w-0">
                                            <p class="font-medium truncate">{{ $project->name }}</p>
                                            @if($project->description)
                                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">{{ \Illuminate\Support\Str::limit($project->description, 40) }}</p>
                                            @endif
                                        </div>
                                        @if($selectedProjectId == $project->id)
                                            <x-icon name="o-check-circle" class="w-4 h-4 text-blue-600 dark:text-blue-400 ml-2 flex-shrink-0" />
                                        @endif
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endif
                    
                    <div class="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                        <button
                            wire:click="openCreateProjectModal"
                            @click="open = false"
                            class="w-full flex items-center justify-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-md transition-colors"
                        >
                            <x-icon name="o-plus" class="w-4 h-4" />
                            <span>Create New Project</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @else
        <x-button 
            wire:click="openProjectSelection" 
            icon="o-folder-open" 
            class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary" 
            responsive
        />
    @endif

    <x-theme-toggle />
    <x-button label="Messages" icon="o-envelope" link="###" class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary" responsive />
    <x-button label="Notifications" icon="o-bell" link="###" class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary" responsive />
    <x-dropdown class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary" icon="o-user" right>
        <x-menu-item title="Logout" icon="o-power" class="text-red-500" wire:click.stop="logout" spinner="logout" />
        <x-menu-item title="Settings" icon="o-cog-6-tooth" class="text-blue-500" wire:click.stop="settings" spinner="settings" />
    </x-dropdown>
 </x-slot:actions>
</x-nav>
</div>