<?php

namespace App\Livewire;

use Illuminate\Validation\Rules\Email;
use Livewire\Component;
use Mary\Traits\Toast;

class Homepage extends Component
{
    use Toast;

    public bool $loginModal = false;
    public bool $registerModal = false;
    public bool $waitListModal = false;
    public $email, $name, $password;

    public function openLoginModal()
    {
        $this->loginModal = true;
    }
    public function openRegisterModal()
    {
        $this->registerModal = true;
    }
    public function closeLoginModal()
    {
        $this->loginModal = false;
    }
    public function closeRegisterModal()
    {
        $this->registerModal = false;
    }

    public function openWaitListModal()
    {
        $this->waitListModal = true;
    }
    public function closeWaitListModal()
    {
        $this->waitListModal = false;
    }

    public function loginUser()
    {
        return redirect()->route('home');
    }
    public function render()
    {
        return view('livewire.homepage');
    }
}