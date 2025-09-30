<div class="min-h-screen p-6" style="background-color: rgb(239, 150, 81);">
    <div class="flex items-center gap-3 mb-4">
        <a href="{{ url()->previous() }}" class="inline-flex items-center justify-center rounded-md border border-secondary/25 bg-white/70 px-2 py-1 hover:bg-white">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-secondary">
                <path fill-rule="evenodd" d="M10.03 3.97a.75.75 0 0 1 0 1.06L5.06 10h15.19a.75.75 0 0 1 0 1.5H5.06l4.97 4.97a.75.75 0 1 1-1.06 1.06l-6.25-6.25a.75.75 0 0 1 0-1.06l6.25-6.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
        </a>
        <x-header title="Settings" subtitle="Manage your profile and preferences" />
    </div>

    <x-tabs wire:model="settingsTab" class="mt-4">
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

            <x-card title="Two-Factor Authentication" subtitle="Secure your account with an authenticator app" class="mt-4">
                @if($twoFactorEnabled)
                    <div class="space-y-3">
                        <div class="text-sm">Two-factor is <span class="font-semibold">enabled</span>.</div>
                        <x-button label="Disable two-factor" wire:click="disableTwoFactor" class="bg-red-600 text-white hover:bg-red-700" />
                        <x-button label="Regenerate recovery codes" wire:click="regenerateRecoveryCodes" class="btn-ghost border-secondary text-secondary hover:bg-secondary/10" />

                        @if(!empty($recoveryCodes))
                            <div class="mt-3">
                                <div class="text-sm font-medium mb-1">Recovery codes</div>
                                <div class="grid grid-cols-2 gap-2">
                                    @foreach($recoveryCodes as $code)
                                        <code class="px-2 py-1 rounded bg-white/70 border border-secondary/25 text-sm">{{ $code }}</code>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="space-y-4">
                        @if($twoFactorQrSvg === '')
                            <x-button label="Enable two-factor" wire:click="enableTwoFactor" class="bg-secondary text-white hover:bg-secondary/80" />
                        @else
                            <div class="flex flex-col md:flex-row items-start gap-6">
                                <div class="bg-white p-3 rounded border border-secondary/25" aria-label="QR code">
                                    @if($twoFactorQrSvg !== '')
                                        {!! $twoFactorQrSvg !!}
                                    @endif
                                </div>
                                <div class="flex-1">
                                    <div class="text-sm opacity-80 mb-2">Scan this QR with your authenticator app, or enter the key manually:</div>
                                    <code class="px-2 py-1 rounded bg-white/70 border border-secondary/25 text-sm">{{ $twoFactorSecretPreview }}</code>
                                    <div class="mt-3">
                                        <x-form wire:submit="confirmTwoFactor">
                                            <x-input wire:model="twoFactorCode" label="Enter 6-digit code" />
                                            <x-slot:actions>
                                                <x-button type="submit" label="Confirm" class="bg-secondary text-white hover:bg-secondary/80" spinner="confirmTwoFactor" />
                                            </x-slot:actions>
                                        </x-form>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
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
                        @if(auth()->user()?->oauth_provider === 'google')
                            <span class="text-sm text-white/90">Connected</span>
                        @else
                            <a href="{{ route('oauth.redirect', 'google') }}">
                                <x-button label="Connect" class="btn-ghost border-secondary text-secondary hover:bg-secondary/10" />
                            </a>
                        @endif
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="inline-flex items-center gap-2">
                            <span class="font-medium">GitHub</span>
                        </div>
                        @if(auth()->user()?->oauth_provider === 'github')
                            <span class="text-sm text-white/90">Connected</span>
                        @else
                            <a href="{{ route('oauth.redirect', 'github') }}">
                                <x-button label="Connect" class="btn-ghost border-secondary text-secondary hover:bg-secondary/10" />
                            </a>
                        @endif
                    </div>
                </div>
            </x-card>
        </x-tab>
    </x-tabs>
</div>
