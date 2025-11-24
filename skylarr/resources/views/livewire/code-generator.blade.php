<div class="h-screen flex flex-col">
    {{-- Navigation Bar --}}
    <livewire:custom-components.navigation-bar 
        :projects="$projects" 
        :selected-project-id="$selectedProjectId"
        :selected-project="$selectedProject"
        :key="'nav-' . ($selectedProjectId ?? 'none')"
    />

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

    {{-- Generation Warning Modal --}}
    <x-modal wire:model="generationWarningModal">
        <x-header title="Code Generation in Progress" subtitle="Switching projects may interrupt generation" />

        <div class="space-y-4">
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <div class="flex items-start gap-3">
                    <x-icon name="o-exclamation-triangle" class="w-6 h-6 text-yellow-600 flex-shrink-0 mt-0.5" />
                    <div>
                        <p class="font-medium text-yellow-900 mb-2">Generation is currently in progress</p>
                        <p class="text-sm text-yellow-800">
                            The current project is generating code. If you switch projects now:
                        </p>
                        <ul class="text-sm text-yellow-800 mt-2 list-disc list-inside space-y-1">
                            <li>The generation will continue in the background</li>
                            <li>You'll receive a notification when it completes</li>
                            <li>You can switch back to view the results</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <x-button 
                    wire:click="confirmProjectSwitch" 
                    label="Switch Anyway" 
                    icon="o-arrow-right" 
                    class="btn-warning flex-1"
                    spinner="confirmProjectSwitch"
                />
                <x-button 
                    wire:click="cancelProjectSwitch" 
                    label="Cancel" 
                    icon="o-x-mark" 
                    class="btn-ghost flex-1"
                />
            </div>
        </div>
    </x-modal>

    {{-- Main Split Layout with Resizable Panels --}}
    <div class="flex-1 flex flex-col overflow-hidden" id="main-container">
        {{-- Main Content Area --}}
        <div class="flex-1 flex overflow-hidden">
            {{-- Left Panel: Chat Interface (Smaller by default) --}}
            <div class="flex flex-col border-r border-gray-300" id="chat-panel" style="width: 350px; min-width: 300px; max-width: 600px; flex-shrink: 0;">
                
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
            <div 
                class="w-1 bg-gray-300 hover:bg-blue-500 cursor-col-resize transition-all relative group" 
                id="resize-handle"
                style="flex-shrink: 0;"
                title="Drag to resize panels"
            >
                {{-- Visual indicator dots --}}
                <div class="absolute inset-y-0 left-1/2 transform -translate-x-1/2 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                    <div class="flex flex-col gap-1">
                        <div class="w-1 h-1 bg-blue-500 rounded-full"></div>
                        <div class="w-1 h-1 bg-blue-500 rounded-full"></div>
                        <div class="w-1 h-1 bg-blue-500 rounded-full"></div>
                    </div>
                </div>
            </div>

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
        (function() {
            let isResizing = false;
            let startX = 0;
            let startWidth = 0;
            
            function initResize() {
                const container = document.getElementById('main-container');
                const chatPanel = document.getElementById('chat-panel');
                const codePanel = document.getElementById('code-panel');
                const resizeHandle = document.getElementById('resize-handle');
                
                if (!container || !chatPanel || !codePanel || !resizeHandle) {
                    return;
                }
                
                // Load saved width from localStorage
                const savedWidth = localStorage.getItem('chat-panel-width');
                if (savedWidth) {
                    const width = parseInt(savedWidth, 10);
                    if (width >= 300 && width <= 600) {
                        chatPanel.style.width = width + 'px';
                    }
                }
                
                // Remove existing listeners to prevent duplicates
                const newResizeHandle = resizeHandle.cloneNode(true);
                resizeHandle.parentNode.replaceChild(newResizeHandle, resizeHandle);
                
                newResizeHandle.addEventListener('mousedown', function(e) {
                    isResizing = true;
                    startX = e.clientX;
                    startWidth = parseInt(window.getComputedStyle(chatPanel).width, 10);
                    
                    document.body.style.cursor = 'col-resize';
                    document.body.style.userSelect = 'none';
                    document.body.style.pointerEvents = 'none';
                    newResizeHandle.style.backgroundColor = '#3b82f6'; // blue-500
                    
                    e.preventDefault();
                    e.stopPropagation();
                });
                
                document.addEventListener('mousemove', function(e) {
                    if (!isResizing) return;
                    
                    const deltaX = e.clientX - startX;
                    const newWidth = startWidth + deltaX;
                    const minWidth = 300;
                    const maxWidth = Math.min(600, window.innerWidth * 0.6); // Max 60% of screen width
                    
                    if (newWidth >= minWidth && newWidth <= maxWidth) {
                        chatPanel.style.width = newWidth + 'px';
                        chatPanel.style.flexShrink = '0';
                        codePanel.style.flex = '1 1 0%';
                    }
                });
                
                document.addEventListener('mouseup', function() {
                    if (isResizing) {
                        isResizing = false;
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                        document.body.style.pointerEvents = '';
                        newResizeHandle.style.backgroundColor = '';
                        
                        // Save width to localStorage
                        const currentWidth = parseInt(window.getComputedStyle(chatPanel).width, 10);
                        localStorage.setItem('chat-panel-width', currentWidth.toString());
                    }
                });
                
                // Prevent text selection while resizing
                newResizeHandle.addEventListener('selectstart', function(e) {
                    e.preventDefault();
                });
                
                // Add touch support for mobile
                let touchStartX = 0;
                let touchStartWidth = 0;
                
                newResizeHandle.addEventListener('touchstart', function(e) {
                    isResizing = true;
                    touchStartX = e.touches[0].clientX;
                    touchStartWidth = parseInt(window.getComputedStyle(chatPanel).width, 10);
                    e.preventDefault();
                });
                
                document.addEventListener('touchmove', function(e) {
                    if (!isResizing) return;
                    
                    const deltaX = e.touches[0].clientX - touchStartX;
                    const newWidth = touchStartWidth + deltaX;
                    const minWidth = 300;
                    const maxWidth = Math.min(600, window.innerWidth * 0.6);
                    
                    if (newWidth >= minWidth && newWidth <= maxWidth) {
                        chatPanel.style.width = newWidth + 'px';
                    }
                    e.preventDefault();
                });
                
                document.addEventListener('touchend', function() {
                    if (isResizing) {
                        isResizing = false;
                        const currentWidth = parseInt(window.getComputedStyle(chatPanel).width, 10);
                        localStorage.setItem('chat-panel-width', currentWidth.toString());
                    }
                });
            }
            
            // Initialize on DOM ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initResize);
            } else {
                initResize();
            }
            
            // Re-initialize when Livewire updates (for dynamic content)
            document.addEventListener('livewire:init', function() {
                Livewire.hook('morph.updated', ({ el, component }) => {
                    // Small delay to ensure DOM is updated
                    setTimeout(initResize, 100);
                });
            });
            
            // Also listen for Livewire updates
            window.addEventListener('livewire:load', initResize);
            window.addEventListener('livewire:update', function() {
                setTimeout(initResize, 100);
            });
        })();
    </script>
</div>
