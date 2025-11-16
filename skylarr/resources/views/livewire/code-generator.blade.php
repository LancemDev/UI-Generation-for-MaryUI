<div class="h-screen flex flex-col">
    {{-- Navigation Bar --}}
    <livewire:custom-components.navigation-bar />

    {{-- Project Selection Modal --}}
    <x-modal wire:model="projectSelectionModal">
        <x-header title="Project Selection" subtitle="Select existing project or create new project" />

        <div class="space-y-4">
            @if($projects && $projects->count() > 0)
                <div class="space-y-2">
                    <label class="label">
                        <span class="label-text font-medium">Select a Project</span>
                    </label>
                    <div class="space-y-1 max-h-60 overflow-y-auto">
                        @foreach($projects as $project)
                            <button
                                wire:click="switchProject({{ $project->id }})"
                                class="w-full text-left px-4 py-3 rounded-lg border border-gray-200 hover:border-blue-500 hover:bg-blue-50 transition-colors {{ $selectedProjectId == $project->id ? 'border-blue-500 bg-blue-50' : '' }}"
                            >
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="font-medium text-gray-900">{{ $project->name }}</p>
                                        @if($project->description)
                                            <p class="text-sm text-gray-500 mt-1">{{ \Illuminate\Support\Str::limit($project->description, 50) }}</p>
                                        @endif
                                    </div>
                                    @if($selectedProjectId == $project->id)
                                        <x-icon name="o-check-circle" class="w-5 h-5 text-blue-600" />
                                    @endif
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="pt-4 border-t border-gray-200">
                <x-button 
                    wire:click="openCreateProjectModal" 
                    label="Create New Project" 
                    icon="o-plus" 
                    class="btn-primary w-full" 
                />
            </div>
        </div>
    </x-modal>

    <x-modal wire:model="createNewProjectModal">
        <x-form wire:submit="createProject">
            <x-input label="Name of Project" placeholder="test101" wire:model="projectName" />
            <x-textarea label="Project Description(optional)" placeholder="my portfolio site" wire:model="projectDescription" />

            <x-slot:actions>
                <x-button label="Create New Project" type="submit" spinner="createProject" icon="o-check" />
            </x-slot:actions>
        </x-form>
    </x-modal>

    {{-- Main Split Layout with Resizable Panels --}}
    <div class="flex-1 flex flex-col overflow-hidden" id="main-container">
        {{-- Top Bar with Project Selector --}}
        <div class="flex items-center justify-between p-4 border-b border-gray-200 bg-gray-50">
            <div class="flex items-center gap-4">
                <h2 class="text-lg font-semibold text-gray-900">Code Generator</h2>
            </div>
            <div class="flex items-center gap-3">
                @if($selectedProject)
                    {{-- Project Selector Dropdown --}}
                    <div class="relative" x-data="{ open: false }">
                        <button
                            @click="open = !open"
                            class="flex items-center gap-2 px-4 py-2 text-sm rounded-lg border border-gray-300 bg-white hover:bg-gray-50 hover:border-blue-500 transition-colors"
                        >
                            <x-icon name="o-folder" class="w-4 h-4 text-gray-500" />
                            <span class="font-medium text-gray-900">{{ $selectedProject->name }}</span>
                            <x-icon name="o-chevron-down" class="w-4 h-4 text-gray-400" />
                        </button>
                        
                        {{-- Dropdown Menu --}}
                        <div
                            x-show="open"
                            @click.away="open = false"
                            x-cloak
                            class="absolute right-0 mt-2 w-64 bg-white rounded-lg shadow-lg border border-gray-200 z-50 max-h-96 overflow-y-auto"
                        >
                            <div class="p-2">
                                <div class="px-3 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider border-b border-gray-200 mb-2">
                                    Projects ({{ $projects->count() }})
                                </div>
                                
                                @if($projects && $projects->count() > 0)
                                    <div class="space-y-1">
                                        @foreach($projects as $project)
                                            <button
                                                wire:click="switchProject({{ $project->id }})"
                                                @click="open = false"
                                                class="w-full text-left px-3 py-2 rounded-md hover:bg-gray-100 transition-colors {{ $selectedProjectId == $project->id ? 'bg-blue-50 text-blue-900' : 'text-gray-700' }}"
                                            >
                                                <div class="flex items-center justify-between">
                                                    <div class="flex-1 min-w-0">
                                                        <p class="font-medium truncate">{{ $project->name }}</p>
                                                        @if($project->description)
                                                            <p class="text-xs text-gray-500 truncate mt-0.5">{{ \Illuminate\Support\Str::limit($project->description, 40) }}</p>
                                                        @endif
                                                    </div>
                                                    @if($selectedProjectId == $project->id)
                                                        <x-icon name="o-check-circle" class="w-4 h-4 text-blue-600 ml-2 flex-shrink-0" />
                                                    @endif
                                                </div>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                                
                                <div class="mt-2 pt-2 border-t border-gray-200">
                                    <button
                                        wire:click="openCreateProjectModal"
                                        @click="open = false"
                                        class="w-full flex items-center justify-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 hover:bg-blue-50 rounded-md transition-colors"
                                    >
                                        <x-icon name="o-plus" class="w-4 h-4" />
                                        <span>Create New Project</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <button
                        wire:click="openProjectSelection"
                        class="flex items-center gap-2 px-4 py-2 text-sm rounded-lg border border-gray-300 bg-white hover:bg-gray-50 hover:border-blue-500 transition-colors"
                    >
                        <x-icon name="o-folder-open" class="w-4 h-4 text-gray-500" />
                        <span class="font-medium text-gray-700">Select Project</span>
                    </button>
                @endif
            </div>
        </div>

        {{-- Main Content Area --}}
        <div class="flex-1 flex overflow-hidden">
            {{-- Left Panel: Chat Interface (Smaller by default) --}}
            <div class="flex flex-col border-r" id="chat-panel" style="width: 350px; min-width: 300px; max-width: 600px;">
                
                <div class="flex-1 flex flex-col min-h-0 overflow-hidden">
                    @if($selectedProject)
                        <livewire:chat-interface :project-id="$selectedProject->id" :key="'chat-' . $selectedProject->id" />
                    @else
                        <div class="flex-1 flex items-center justify-center text-gray-500">
                            <div class="text-center">
                                <x-icon name="o-folder-open" class="w-12 h-12 mx-auto mb-4 opacity-50" />
                                <p class="text-sm">Select a project to start chatting</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Resize Handle --}}
            <div class="w-1 bg-secondary-200 hover:bg-sedondary-300 cursor-col-resize transition-colors" id="resize-handle"></div>

            {{-- Right Panel: Code Generation Engine (Larger by default) --}}
            <div class="flex-1 flex flex-col" id="code-panel">
                <div class="flex-1">
                    @if($selectedProject)
                        <livewire:code-generation-engine :project-id="$selectedProject->id" :key="'code-' . $selectedProject->id" />
                    @else
                        <div class="flex-1 flex items-center justify-center text-gray-500">
                            <div class="text-center">
                                <x-icon name="o-code-bracket" class="w-12 h-12 mx-auto mb-4 opacity-50" />
                                <p class="text-sm">Select a project to start generating code</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('main-container');
            const chatPanel = document.getElementById('chat-panel');
            const codePanel = document.getElementById('code-panel');
            const resizeHandle = document.getElementById('resize-handle');
            
            let isResizing = false;
            let startX = 0;
            let startWidth = 0;
            
            // Load saved width from localStorage
            const savedWidth = localStorage.getItem('chat-panel-width');
            if (savedWidth) {
                chatPanel.style.width = savedWidth + 'px';
            }
            
            resizeHandle.addEventListener('mousedown', function(e) {
                isResizing = true;
                startX = e.clientX;
                startWidth = parseInt(window.getComputedStyle(chatPanel).width, 10);
                
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';
                
                e.preventDefault();
            });
            
            document.addEventListener('mousemove', function(e) {
                if (!isResizing) return;
                
                const width = startWidth + e.clientX - startX;
                const minWidth = 300;
                const maxWidth = 600;
                
                if (width >= minWidth && width <= maxWidth) {
                    chatPanel.style.width = width + 'px';
                }
            });
            
            document.addEventListener('mouseup', function() {
                if (isResizing) {
                    isResizing = false;
                    document.body.style.cursor = '';
                    document.body.style.userSelect = '';
                    
                    // Save width to localStorage
                    const currentWidth = parseInt(window.getComputedStyle(chatPanel).width, 10);
                    localStorage.setItem('chat-panel-width', currentWidth);
                }
            });
            
            // Prevent text selection while resizing
            resizeHandle.addEventListener('selectstart', function(e) {
                e.preventDefault();
            });
        });
    </script>
</div>
