<div class="p-6 max-w-3xl">
    <x-header title="Settings" subtitle="Manage your account" separator />

    <x-tabs wire:model="tab">
        <x-tab name="profile" label="Profile" icon="o-user">
            <x-form wire:submit="saveProfile">
                <x-input label="Name" wire:model="name" />
                <x-input label="Email" wire:model="email" type="email" />
                <x-textarea label="Bio" wire:model="bio" rows="3" hint="Max 500 characters" />
                <x-select label="Timezone" wire:model="timezone" :options="$timezones" icon="o-globe-alt" />

                <x-slot:actions>
                    <x-button label="Save profile" class="btn-primary" type="submit" spinner="saveProfile" />
                </x-slot:actions>
            </x-form>
        </x-tab>

        <x-tab name="notifications" label="Notifications" icon="o-bell">
            <x-form wire:submit="saveNotifications">
                <x-toggle label="Email notifications" wire:model="emailNotifications" hint="Messages and mentions" right />
                <x-toggle label="Weekly digest" wire:model="weeklyDigest" hint="A summary every Monday" right />
                <x-toggle label="Product updates" wire:model="productUpdates" hint="New features and changes" right />

                <x-slot:actions>
                    <x-button label="Save preferences" class="btn-primary" type="submit" spinner="saveNotifications" />
                </x-slot:actions>
            </x-form>
        </x-tab>
    </x-tabs>
</div>
