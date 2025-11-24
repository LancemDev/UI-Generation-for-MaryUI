<x-layouts.app-with-sidebar>
    @php
        $users = [
            ['id' => 1, 'name' => 'Joe'],
            ['id' => 2,'name' => 'Mary','disabled' => true] // <-- this
        ];
    @endphp
    
    <x-select label="Users" :options="$users" wire:model="selectedUser" />
</x-layouts.app-with-sidebar>
