<div>
    <x-modal wire:model="passwordRequestResetModal">
        <x-header title="Reset Your Password" />
        <x-form wire:submit="sendPasswordResetLink">
            <x-input wire:model="email" label="Email" placeholder="Enter your email" type="email" inline icon="o-envelope" />

            <x-slot:actions>
                <x-button type="submit" label="Send" class="rounded-md bg-green-800 py-2 px-4 border border-transparent text-center text-sm text-white transition-all shadow-md hover:shadow-lg focus:bg-green-700 focus:shadow-none active:bg-green-700 hover:bg-green-700 active:shadow-none disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none ml-2" spinner="sendPasswordResetLink" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
