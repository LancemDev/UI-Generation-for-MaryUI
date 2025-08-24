<?php

namespace App\Livewire;

use Illuminate\Validation\Rules\Email;
use Livewire\Component;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class Homepage extends Component
{
    use Toast;

    public bool $loginModal, $registerModal, $waitListModal = false;

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
        // return redirect()->route('home');
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'min:6'],
        ]);

        if(Auth::attempt(['email' => $this->email, 'password' => $this->password])){
            session()->regenerate();
            $this->success('Login successful!');
            return redirect()->route('dashboard');
        } else {
            $this->error('Invalid credentials. Please try again.');
        }
    }

    public function register()
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:6'],
        ]);

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        $this->success('Registration successful!');
        Auth::login($user);
        return redirect()->route('dashboard');
    }

    public function render()
    {
        return view('livewire.homepage');
    }
}