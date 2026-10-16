<?php

namespace App\Livewire;

use Livewire\Component;
use Mary\Traits\Toast;

class __CLASS__ extends Component
{
    use Toast;

    public array $stats = [
        ['title' => 'Revenue', 'value' => '$48,300', 'icon' => 'o-currency-dollar', 'description' => '+12% this month'],
        ['title' => 'Orders', 'value' => '312', 'icon' => 'o-shopping-cart', 'description' => '+4% this month'],
        ['title' => 'Customers', 'value' => '1,204', 'icon' => 'o-users', 'description' => '+38 new'],
    ];

    public array $headers = [
        ['key' => 'id', 'label' => 'Order', 'class' => 'w-1'],
        ['key' => 'customer', 'label' => 'Customer'],
        ['key' => 'total', 'label' => 'Total'],
        ['key' => 'status', 'label' => 'Status'],
    ];

    public array $orders = [
        ['id' => 1042, 'customer' => 'Ada Lovelace', 'total' => '$240.00', 'status' => 'Paid'],
        ['id' => 1041, 'customer' => 'Alan Turing', 'total' => '$89.50', 'status' => 'Pending'],
        ['id' => 1040, 'customer' => 'Grace Hopper', 'total' => '$1,120.00', 'status' => 'Paid'],
        ['id' => 1039, 'customer' => 'Linus Torvalds', 'total' => '$34.99', 'status' => 'Refunded'],
    ];

    public array $activity = [
        ['name' => 'Ada Lovelace', 'action' => 'placed order #1042', 'time' => '5 min ago'],
        ['name' => 'Alan Turing', 'action' => 'updated billing details', 'time' => '1 hour ago'],
        ['name' => 'Grace Hopper', 'action' => 'left a 5-star review', 'time' => '3 hours ago'],
    ];

    public function refresh(): void
    {
        $this->success('Dashboard refreshed.');
    }

    public function render()
    {
        return view('__VIEW__');
    }
}
