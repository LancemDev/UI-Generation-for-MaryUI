<div>
    {{-- To attain knowledge, add things every day; To attain wisdom, subtract things every day. --}}
    {{-- This file should be the root of 2 other files. Basically a layout file --}}
    {{-- So chat and engine--}}

    {{-- Project Selection Modal --}}
    <x-modal wire:model="showProjectModal" persistent>
        <x-card class="w-full max-w-2xl">
            <x-slot:title>
                <div class="flex items-center gap-2">
                    <x-icon name="o-folder" class="w-6 h-6 text-secondary" />
                    <span>Select or Create Project</span>
                </div>
            </x-slot:title>
            
            <div class="space-y-6">
                {{-- Existing Projects --}}
                @if(count($projects) > 0)
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Your Projects</h3>
                        <div class="grid gap-3 max-h-60 overflow-y-auto">
                            @foreach($projects as $project)
                                <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 cursor-pointer transition-colors"
                                     wire:click="selectProject({{ $project['id'] }})">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1">
                                            <h4 class="font-medium text-gray-900">{{ $project['name'] }}</h4>
                                            @if($project['description'])
                                                <p class="text-sm text-gray-600 mt-1">{{ $project['description'] }}</p>
                                            @endif
                                            <div class="flex items-center gap-4 mt-2 text-xs text-gray-500">
                                                <span>Created: {{ \Carbon\Carbon::parse($project['created_at'])->format('M j, Y') }}</span>
                                                @if($project['last_accessed_at'])
                                                    <span>Last used: {{ \Carbon\Carbon::parse($project['last_accessed_at'])->diffForHumans() }}</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="px-2 py-1 text-xs rounded-full 
                                                @if($project['status'] === 'active') bg-green-100 text-green-800
                                                @elseif($project['status'] === 'creating') bg-yellow-100 text-yellow-800
                                                @else bg-gray-100 text-gray-800 @endif">
                                                {{ ucfirst($project['status']) }}
                                            </span>
                                            <button wire:click.stop="deleteProject({{ $project['id'] }})"
                                                    class="text-red-500 hover:text-red-700 p-1"
                                                    onclick="return confirm('Are you sure you want to delete this project?')">
                                                <x-icon name="o-trash" class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
                
                {{-- Create New Project --}}
                <div class="border-t pt-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-3">Create New Project</h3>
                    <div class="space-y-4">
                        <x-input label="Project Name" 
                                 wire:model="newProjectName" 
                                 placeholder="Enter project name"
                                 class="w-full" />
                        
                        <x-textarea label="Description (Optional)" 
                                   wire:model="newProjectDescription" 
                                   placeholder="Describe your project..."
                                   rows="3"
                                   class="w-full" />
                        
                        <div class="flex justify-end gap-3">
                            <x-button wire:click="closeProjectModal" class="btn-ghost">
                                Cancel
                            </x-button>
                            <x-button wire:click="createProject" 
                                     class="btn-primary"
                                     :disabled="empty($newProjectName) || $isCreatingProject">
                                <x-icon name="o-plus" class="w-4 h-4 mr-2" />
                                @if($isCreatingProject)
                                    Creating...
                                @else
                                    Create Project
                                @endif
                            </x-button>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
    </x-modal>

    <livewire:custom-components.navigation-bar />

    <div id="gg-ui-root" class="flex h-screen overflow-hidden relative bg-primary">
        {{-- Project Selector Button --}}
        <button wire:click="openProjectModal"
                class="absolute top-3 right-3 z-20 inline-flex items-center gap-2 rounded-md border border-secondary text-secondary bg-white px-3 py-1.5 text-sm font-medium shadow-sm focus:outline-none hover:bg-secondary/10"
                type="button">
            <x-icon name="o-folder-open" class="h-4 w-4" />
            <span class="hidden sm:inline">
                @if($selectedProject)
                    {{ $selectedProject->name }}
                @else
                    Select Project
                @endif
            </span>
        </button>

        <button id="sidebar-toggle"
                class="absolute top-3 left-3 z-20 inline-flex items-center gap-2 rounded-md border border-secondary text-secondary bg-white px-3 py-1.5 text-sm font-medium shadow-sm focus:outline-none hover:bg-secondary/10"
                type="button"
                aria-expanded="true"
                aria-controls="gg-sidebar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
                <path fill-rule="evenodd" d="M3.75 5.25a.75.75 0 0 1 .75-.75h15a.75.75 0 0 1 0 1.5h-15a.75.75 0 0 1-.75-.75Zm0 6a.75.75 0 0 1 .75-.75h15a.75.75 0 0 1 0 1.5h-15a.75.75 0 0 1-.75-.75Zm0 6a.75.75 0 0 1 .75-.75h8.5a.75.75 0 0 1 0 1.5h-8.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
            </svg>
            <span class="hidden sm:inline"></span>
        </button>

        <div id="gg-sidebar" class="relative h-full border-r border-secondary/25 overflow-y-auto transition-[width] duration-200 ease-in-out w-[28rem] min-w-[14rem] max-w-[60vw]">
            <div id="gg-sidebar-content" class="h-full p-4">
                @if($selectedProject)
                    <livewire:chat-interface :project-id="$selectedProject->id" class="w-full h-full" />
                @else
                    <div class="flex items-center justify-center h-full text-white/60">
                        <div class="text-center">
                            <x-icon name="o-folder-open" class="w-12 h-12 mx-auto mb-4" />
                            <p>Select a project to start chatting</p>
                        </div>
                    </div>
                @endif
            </div>
            <div id="gg-drag-handle" class="absolute top-0 right-0 h-full w-1.5 cursor-col-resize bg-transparent hover:bg-secondary/35"></div>
        </div>

        <div id="gg-main" class="flex-1 h-full overflow-y-auto relative">
            <div class="h-full p-4">
                @if($selectedProject)
                    <livewire:code-generation-engine :project-id="$selectedProject->id" class="w-full h-full" />
                @else
                    <div class="flex items-center justify-center h-full text-white/60">
                        <div class="text-center">
                            <x-icon name="o-code-bracket" class="w-12 h-12 mx-auto mb-4" />
                            <p>Select a project to start generating code</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <style>
        /* Collapsed state rules */
        #gg-sidebar.is-collapsed {
            width: 0 !important;
            min-width: 0 !important;
            border-right-width: 0;
        }
        #gg-sidebar.is-collapsed #gg-sidebar-content { display: none; }
        /* Improve hit area for the toggle on top of content */
        #sidebar-toggle { pointer-events: auto; }
        /* hover states handled by Tailwind classes above */
    </style>

    <script>
        (function () {
            const sidebar = document.getElementById('gg-sidebar');
            const toggleBtn = document.getElementById('sidebar-toggle');
            const dragHandle = document.getElementById('gg-drag-handle');
            const STORAGE_KEY_WIDTH = 'gg.sidebar.width';
            const STORAGE_KEY_COLLAPSED = 'gg.sidebar.collapsed';

            // Restore persisted state
            try {
                const savedWidth = localStorage.getItem(STORAGE_KEY_WIDTH);
                if (savedWidth) {
                    const width = parseInt(savedWidth, 10);
                    if (!Number.isNaN(width)) {
                        sidebar.style.width = width + 'px';
                    }
                }
                const savedCollapsed = localStorage.getItem(STORAGE_KEY_COLLAPSED);
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('is-collapsed');
                    toggleBtn.setAttribute('aria-expanded', 'false');
                }
            } catch (_) {}

            // Toggle collapse
            toggleBtn.addEventListener('click', function () {
                const collapsed = sidebar.classList.toggle('is-collapsed');
                toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                try { localStorage.setItem(STORAGE_KEY_COLLAPSED, String(collapsed)); } catch (_) {}
            });

            // Drag to resize (enabled primarily for md+ but works if space allows)
            let isDragging = false;
            let startX = 0;
            let startWidth = 0;

            const minWidth = 224; // 14rem
            const maxWidthRatio = 0.6; // 60% of viewport width

            function onMouseMove(e) {
                if (!isDragging) return;
                const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
                const maxWidth = Math.floor(viewportWidth * maxWidthRatio);
                const dx = e.clientX - startX;
                let newWidth = startWidth + dx;
                if (newWidth < minWidth) newWidth = minWidth;
                if (newWidth > maxWidth) newWidth = maxWidth;
                sidebar.style.width = newWidth + 'px';
            }

            function onMouseUp() {
                if (!isDragging) return;
                isDragging = false;
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
                try {
                    const numeric = parseInt(sidebar.style.width, 10);
                    if (!Number.isNaN(numeric)) {
                        localStorage.setItem(STORAGE_KEY_WIDTH, String(numeric));
                    }
                } catch (_) {}
            }

            dragHandle.addEventListener('mousedown', function (e) {
                // If collapsed, expand first
                if (sidebar.classList.contains('is-collapsed')) {
                    sidebar.classList.remove('is-collapsed');
                    toggleBtn.setAttribute('aria-expanded', 'true');
                    try { localStorage.setItem(STORAGE_KEY_COLLAPSED, 'false'); } catch (_) {}
                }
                isDragging = true;
                startX = e.clientX;
                startWidth = sidebar.getBoundingClientRect().width;
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
        })();
    </script>
</div>
