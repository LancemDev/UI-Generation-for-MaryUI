<?php

namespace App\Livewire;

use Livewire\Component;
use Mary\Traits\Toast;

class PasswordReset extends Component
{
    use Toast;
    public $email = '';
    public $passwordRequestResetModal = true;
    public function render()
    {
        return view('livewire.password-reset');
    }
}
