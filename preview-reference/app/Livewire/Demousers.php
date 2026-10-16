<?php

namespace App\Livewire;

use Livewire\Component;

class Demousers extends Component
{
    public $selectedUser;

    public function mount()
    {
        $this->selectedUser = null;
    }

    public function render()
    {
        return view('livewire.demousers');
    }
}
