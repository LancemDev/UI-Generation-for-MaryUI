<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Writer;
use BaconQrCode\Renderer\Image\Svg;
use Mary\Traits\Toast;

class Settings extends Component
{
    use Toast;
    public string $name = '';
    public string $email = '';
    public string $current_password = '';
    public string $new_password = '';
    public string $new_password_confirmation = '';
    public string $settingsTab = 'profile';
    public bool $twoFactorEnabled = false;
    public string $twoFactorQrSvg = '';
    public string $twoFactorQrDataUrl = '';
    public string $twoFactorSecretPreview = '';
    public string $twoFactorCode = '';
    public array $recoveryCodes = [];

    public function mount(): void
    {
        $user = User::query()->find(Auth::id());
        if ($user) {
            $this->name = (string)($user->name ?? '');
            $this->email = (string)($user->email ?? '');
        }
        $this->initTwoFactor();
    }

    public function saveProfile(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::query()->find(Auth::id());
        if ($user) {
            $user->name = $this->name;
            $user->email = $this->email;
            $user->save();
        }

        $this->dispatch('toast', type: 'success', title: 'Saved', message: 'Profile updated');
    }

    public function changePassword(): void
    {
        // Implement as needed (current_password, new_password validation)
        $this->dispatch('toast', type: 'info', title: 'Coming soon', message: 'Password change flow pending');
    }

    public function initTwoFactor(): void
    {
        $user = User::query()->find(Auth::id());
        $this->twoFactorEnabled = (bool) ($user?->two_factor_secret);
    }

    public function enableTwoFactor(): void
    {
        $user = Auth::user();
        if (!$user) return;

        // Generate secret and otpauth URL
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();
        $appName = config('app.name', 'Skylarr');
        $otpauth = $google2fa->getQRCodeUrl($appName, (string)$user->email, $secret);

        // SVG QR (bacon-qr-code v1 API)
        $renderer = new Svg();
        $renderer->setHeight(200);
        $renderer->setWidth(200);
        $writer = new Writer($renderer);
        $this->twoFactorQrSvg = $writer->writeString($otpauth);
        $this->twoFactorQrDataUrl = 'data:image/svg+xml;base64,' . base64_encode($this->twoFactorQrSvg);
        $this->twoFactorSecretPreview = $secret;
    }

    public function confirmTwoFactor(): void
    {
        $user = User::query()->find(Auth::id());
        if (!$user || $this->twoFactorSecretPreview === '') return;

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($this->twoFactorSecretPreview, preg_replace('/\s+/', '', $this->twoFactorCode));
        if (!$valid) {
            $this->dispatch('toast', type: 'error', title: 'Invalid code', message: 'Please try again');
            return;
        }

        // Persist secret + recovery codes
        $codes = collect(range(1, 8))->map(fn() => bin2hex(random_bytes(5)))->all();
        $user->two_factor_secret = $this->twoFactorSecretPreview;
        $user->two_factor_recovery_codes = $codes;
        $user->save();

        $this->twoFactorEnabled = true;
        $this->recoveryCodes = $codes;
        $this->twoFactorSecretPreview = '';
        $this->twoFactorQrSvg = '';
        $this->twoFactorCode = '';

        $this->dispatch('toast', type: 'success', title: 'Two-factor enabled', message: 'Store your recovery codes safely');
    }

    public function disableTwoFactor(): void
    {
        $user = User::query()->find(Auth::id());
        if (!$user) return;
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->save();
        $this->twoFactorEnabled = false;
        $this->dispatch('toast', type: 'success', title: 'Two-factor disabled');
    }

    public function regenerateRecoveryCodes(): void
    {
        $user = User::query()->find(Auth::id());
        if (!$user || !$user->two_factor_secret) return;
        $codes = collect(range(1, 8))->map(fn() => bin2hex(random_bytes(5)))->all();
        $user->two_factor_recovery_codes = $codes;
        $user->save();
        $this->recoveryCodes = $codes;
        $this->dispatch('toast', type: 'success', title: 'Recovery codes regenerated');
    }
    public function render()
    {
        return view('livewire.settings');
    }
}
