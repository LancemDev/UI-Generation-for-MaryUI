<?php

namespace App\Livewire;

use Livewire\Component;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\Password;

class PasswordReset extends Component
{
    use Toast;
    public $email = '';
    public $passwordRequestResetModal = true;

    public function submit()
    {
        $this->validate([
            'email' => 'required|email',
        ]);

        $status = Password::sendResetLink(
            ['email' => $this->email]
        );

        if ($status == Password::RESET_LINK_SENT) {
            $this->success("Success", __($status));
            $this->passwordRequestResetModal = false;
            return redirect()->route('login');
        } else {
            $this->error("Error", __($status));
        }
    }
    public function render()
    {
        return view('livewire.password-reset');
    }
}
