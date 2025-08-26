<?php

namespace App\Livewire;

use Livewire\Component;
use Mary\Traits\Toast;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetForm extends Component
{
    use Toast;
    public $token;
    public $email = '';
    public $password = '';
    public $password_confirmation = '';

    public function resetPassword()
    {
        $this->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            [
                'token' => $this->token,
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
            ],
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status == Password::PASSWORD_RESET) {
            $this->toast(
                type: 'success',
                title: 'Success',
                timeout: 5000,
                description: __($status),
                redirectTo: route('login')
            );
        } else {
            $this->error("Error", __($status));
        }
    }
    public function render()
    {
        return view('livewire.password-reset-form', [
            'token' => $this->token,
        ]);
    }
}
