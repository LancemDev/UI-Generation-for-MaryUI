<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;

class Settings extends Component
{
    public string $name = '';
    public string $email = '';

    public function mount(): void
    {
        $user = Auth::user();
        if ($user) {
            $this->name = (string)($user->name ?? '');
            $this->email = (string)($user->email ?? '');
        }
    }

    public function saveProfile(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = Auth::user();
        if ($user) {
            $user->forceFill([
                'name' => $this->name,
                'email' => $this->email,
            ])->save();
        }

        $this->dispatch('toast', type: 'success', title: 'Saved', message: 'Profile updated');
    }

    public function changePassword(): void
    {
        // Implement as needed (current_password, new_password validation)
        $this->dispatch('toast', type: 'info', title: 'Coming soon', message: 'Password change flow pending');
    }
    public function render()
    {
        return view('livewire.settings');
    }
}
