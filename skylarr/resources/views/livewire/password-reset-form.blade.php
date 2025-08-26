<div>
    {{-- Be like water. --}}
    <x-modal wire:model="PasswordResetFormModal">
        <x-header title="Reset Your Password" />
        <x-form wire:submit="resetPassword">
            <input type="hidden" name="token" value="{{ $token }}">
            <x-input wire:model="email" label="Email" placeholder="Enter your email" type="email" inline icon="o-envelope" />
            <x-input wire:model="password" label="New Password" placeholder="Enter your new password" type="password" inline icon="o-lock-closed" />
            <x-input wire:model="password_confirmation" label="Confirm Password" placeholder="Confirm your new password" type="password" inline icon="o-lock-closed" />
            <x-slot:actions>
                <x-button type="submit" label="Reset Password" class="rounded-md bg-green-800 py-2 px-4 border border-transparent text-center text-sm text-white transition-all shadow-md hover:shadow-lg focus:bg-green-700 focus:shadow-none active:bg-green-700 hover:bg-green-700 active:shadow-none disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none ml-2" spinner="resetPassword" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
