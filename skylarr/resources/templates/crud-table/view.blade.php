<div class="p-6">
    <x-header title="Users" subtitle="Manage your team" separator>
        <x-slot:middle class="!justify-end">
            <x-input icon="o-magnifying-glass" placeholder="Search..." wire:model.live.debounce="search" clearable />
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="Add user" icon="o-plus" class="btn-primary" wire:click="create" />
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <x-table :headers="$headers" :rows="$rows" striped>
            @scope('cell_role', $user)
                <x-badge :value="ucfirst($user['role'])" class="badge-soft" />
            @endscope
            @scope('actions', $user)
                <div class="flex gap-1">
                    <x-button icon="o-pencil" wire:click="edit({{ $user['id'] }})" class="btn-ghost btn-sm" />
                    <x-button icon="o-trash" wire:click="delete({{ $user['id'] }})" wire:confirm="Delete this user?" spinner class="btn-ghost btn-sm text-error" />
                </div>
            @endscope
        </x-table>
    </x-card>

    <x-modal wire:model="editing" :title="$editingId ? 'Edit user' : 'Add user'" separator>
        <x-form wire:submit="save">
            <x-input label="Name" wire:model="name" icon="o-user" />
            <x-input label="Email" wire:model="email" type="email" icon="o-envelope" />
            <x-select label="Role" wire:model="role" :options="$roles" />

            <x-slot:actions>
                <x-button label="Cancel" @click="$wire.editing = false" />
                <x-button label="Save" class="btn-primary" type="submit" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
