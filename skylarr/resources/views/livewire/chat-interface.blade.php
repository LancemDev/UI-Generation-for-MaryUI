<div>
    {{-- A good traveler has no fixed plans and is not intent upon arriving. --}}
    <x-form wire:submit="sendMessage">
        <x-input wire:model="message" label="Message" />

        <x-slot:actions>
            <x-button type="submit" label="Send" />
        </x-slot:actions>
    </x-form>
</div>
