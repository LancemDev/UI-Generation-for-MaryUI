<div>
    {{-- A good traveler has no fixed plans and is not intent upon arriving. --}}
    <div class="mt-10"></div>
    <x-form wire:submit="sendMessage">
        <x-textarea wire:model="message" label="" class="" />

        <x-slot:actions>
            <x-button type="submit" label="Send" spinner="sendMessage" icon="o-paper-airplane" class="bg-secondary text-white hover:bg-secondary/50" />
        </x-slot:actions>
    </x-form>
</div>
