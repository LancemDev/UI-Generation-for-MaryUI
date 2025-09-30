<div class="min-h-screen p-6" style="background-color: rgb(239, 150, 81);">
    <div class="flex items-center gap-3 mb-4">
        <a href="{{ url()->previous() }}" class="inline-flex items-center justify-center rounded-md border border-secondary/25 bg-white/70 px-2 py-1 hover:bg-white">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-secondary">
                <path fill-rule="evenodd" d="M10.03 3.97a.75.75 0 0 1 0 1.06L5.06 10h15.19a.75.75 0 0 1 0 1.5H5.06l4.97 4.97a.75.75 0 1 1-1.06 1.06l-6.25-6.25a.75.75 0 0 1 0-1.06l6.25-6.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
        </a>
        <x-header title="Settings" subtitle="Manage your profile and preferences" />
    </div>

    <x-tabs class="mt-4">
        <x-tab name="profile" label="Profile" icon="o-user">
            <x-card title="Profile information" subtitle="Update your personal details">
                <x-form wire:submit="saveProfile">
                    <x-input wire:model="name" label="Name" icon="o-user" />
                    <x-input wire:model="email" label="Email" icon="o-envelope" />
                    <x-slot:actions>
                        <x-button type="submit" label="Save" class="bg-secondary text-white hover:bg-secondary/80" spinner="saveProfile" />
                    </x-slot:actions>
                </x-form>
            </x-card>
        </x-tab>

        <x-tab name="security" label="Security" icon="o-lock-closed">
            <x-card title="Password" subtitle="Change your password">
                <x-form wire:submit="changePassword">
                    <x-input type="password" wire:model="current_password" label="Current password" icon="o-lock-closed" />
                    <x-input type="password" wire:model="new_password" label="New password" icon="o-key" />
                    <x-input type="password" wire:model="new_password_confirmation" label="Confirm new password" icon="o-key" />
                    <x-slot:actions>
                        <x-button type="submit" label="Update password" class="bg-secondary text-white hover:bg-secondary/80" spinner="changePassword" />
                    </x-slot:actions>
                </x-form>
            </x-card>
        </x-tab>

        <x-tab name="appearance" label="Appearance" icon="o-swatch">
            <x-card title="Theme" subtitle="Choose your preferred theme">
                <div class="flex items-center gap-3">
                    <x-theme-toggle />
                    <span class="text-sm opacity-80">Toggle light/dark</span>
                </div>
            </x-card>
        </x-tab>

        <x-tab name="connections" label="Connections" icon="o-link">
            <x-card title="Connected accounts" subtitle="Manage your OAuth providers">
                <div class="flex flex-col gap-2">
                    <div class="flex items-center justify-between">
                        <div class="inline-flex items-center gap-2">
                            <span class="font-medium">Google</span>
                        </div>
                        <x-button label="Connect" class="btn-ghost border-secondary text-secondary hover:bg-secondary/10" />
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="inline-flex items-center gap-2">
                            <span class="font-medium">GitHub</span>
                        </div>
                        <x-button label="Connect" class="btn-ghost border-secondary text-secondary hover:bg-secondary/10" />
                    </div>
                </div>
            </x-card>
        </x-tab>
    </x-tabs>
</div>
