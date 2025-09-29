<?php

namespace App\Livewire;

use Livewire\Component;
use Mary\Traits\Toast;

class ChatInterface extends Component
{
    use Toast;

    public function sendMessage()
    {
        $this->success('Message sent successfully');
    }

    public function render()
    {
        return view('livewire.chat-interface');
    }
}
