<?php

namespace App\Livewire;

use Livewire\Component;
use Mary\Traits\Toast;

class __CLASS__ extends Component
{
    use Toast;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required|min:8',
        ]);

        // Demo only: there is no user database in the preview.
        $this->success('Signed in as ' . $this->email);
        $this->reset('password');
    }

    public function forgotPassword(): void
    {
        $this->info('A reset link would be emailed to you.');
    }

    public function render()
    {
        return view('__VIEW__');
    }
}
