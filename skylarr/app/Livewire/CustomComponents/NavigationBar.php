<?php

namespace App\Livewire\CustomComponents;

use Livewire\Component;
use Mary\Traits\Toast;

use Illuminate\Support\Facades\Auth;
class NavigationBar extends Component
{
    use Toast;


    public function logout()
    {
        Auth::logout();
        $this->success('Logged out successfully');
        return redirect()->route('welcome');
    }
    public function settings()
    {
        return redirect()->route('settings');
    }
    
    public function render()
    {
        return view('livewire.custom-components.navigation-bar');
    }
}
