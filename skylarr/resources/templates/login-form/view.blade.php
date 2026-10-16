<div class="min-h-[80vh] flex items-center justify-center p-6">
    <x-card title="Welcome back" subtitle="Sign in to your account" shadow class="w-full max-w-md">
        <x-form wire:submit="login">
            <x-input label="Email" wire:model="email" type="email" icon="o-envelope" placeholder="you@example.com" />
            <x-password label="Password" wire:model="password" right />
            <div class="flex items-center justify-between">
                <x-checkbox label="Remember me" wire:model="remember" />
                <x-button label="Forgot password?" class="btn-link btn-sm" wire:click="forgotPassword" />
            </div>

            <x-slot:actions>
                <x-button label="Sign in" class="btn-primary w-full" type="submit" spinner="login" />
            </x-slot:actions>
        </x-form>
    </x-card>
</div>
