<div class="h-screen flex flex-col bg-gray-50">
    {{-- Navigation Bar --}}
    <livewire:custom-components.navigation-bar />

    {{-- Project Selection Modal --}}
    <x-modal wire:model="projectSelectionModal">
        <x-header title="Project Selection" subtitle="Select existing project or create new project" />

        <x-form wire:submit="submitProjectCreation">
            <x-select label="Projects" :options="$projects" single>
                <x-slot:prepend>
                    <x-button icon="o-chevron-double-right" class="join-item" />
                </x-slot:prepend>
                <x-slot:append>
                    <x-button wire:click="openCreateProjectModal" label="Create" icon="o-plus" class="join-item btn-primary" />
                </x-slot:append>
            </x-select>

            <x-slot:actions>
                <x-button label="Proceed" type="submit" spinner="submitProjectCreation" icon="o-check-circle" />
            </x-slot:actions>
        </x-form>
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

    {{-- Main Split Layout --}}
    <div class="flex-1 flex overflow-hidden">
        {{-- Left Panel: Chat Interface --}}
        <div class="w-1/2 border-r border-gray-200 flex flex-col bg-white">
            <div class="p-4 border-b border-gray-200 bg-gray-50">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">AI Assistant</h2>
                    <div class="flex items-center gap-2">
                        <div class="w-2 h-2 bg-green-400 rounded-full"></div>
                        <span class="text-sm text-gray-600">Online</span>
                    </div>
                </div>
            </div>
            
            <div class="flex-1 flex flex-col">
                @if($selectedProject)
                    <livewire:chat-interface :project-id="$selectedProject->id" />
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

        {{-- Right Panel: Code Generation Engine --}}
        <div class="w-1/2 flex flex-col bg-white">
            <div class="p-4 border-b border-gray-200 bg-gray-50">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">Code Generator</h2>
                    @if($selectedProject)
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
                                {{ $selectedProject->name }}
                            </span>
                        </div>
                    @endif
                </div>
            </div>
            
            <div class="flex-1">
                @if($selectedProject)
                    <livewire:code-generation-engine :project-id="$selectedProject->id" />
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
