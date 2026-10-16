<?php

namespace App\Livewire;

use Livewire\Component;
use Mary\Traits\Toast;

class __CLASS__ extends Component
{
    use Toast;

    public string $tab = 'profile';

    public string $name = 'Ada Lovelace';

    public string $email = 'ada@example.com';

    public string $bio = 'Mathematician and the first programmer.';

    public string $timezone = 'Europe/London';

    public bool $emailNotifications = true;

    public bool $weeklyDigest = false;

    public bool $productUpdates = true;

    public array $timezones = [
        ['id' => 'Europe/London', 'name' => 'London (GMT)'],
        ['id' => 'America/New_York', 'name' => 'New York (EST)'],
        ['id' => 'Africa/Nairobi', 'name' => 'Nairobi (EAT)'],
        ['id' => 'Asia/Tokyo', 'name' => 'Tokyo (JST)'],
    ];

    public function saveProfile(): void
    {
        $this->validate([
            'name' => 'required|min:2',
            'email' => 'required|email',
            'bio' => 'max:500',
        ]);

        $this->success('Profile saved.');
    }

    public function saveNotifications(): void
    {
        $this->success('Notification preferences saved.');
    }

    public function render()
    {
        return view('__VIEW__');
    }
}
