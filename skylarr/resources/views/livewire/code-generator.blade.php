<div>
    {{-- To attain knowledge, add things every day; To attain wisdom, subtract things every day. --}}
    {{-- This file should be the root of 2 other files. Basically a layout file --}}
    {{-- So chat and engine--}}

    <livewire:custom-components.navigation-bar />

    {{-- Project Selection Modal --}}
    <x-modal wire:model="projectSelectionModal">
        <x-header title="Project Selection" subtitle="Select existing project or create new project" />

        <x-form wire:submit="submitProjectCreation">
            <x-select label="Projects"  :options="$projects" single>
                <x-slot:prepend>
                    {{-- Add `join-item` to all prepended elements --}}
                    <x-button icon="o-chevron-double-right" class="join-item" />
                </x-slot:prepend>
                <x-slot:append>
                    {{-- Add `join-item` to all appended elements --}}
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
    

    <livewire:chat-interface />



    <livewire:code-generation-engine />

</div>
