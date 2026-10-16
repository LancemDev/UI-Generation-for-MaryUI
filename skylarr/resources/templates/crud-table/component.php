<?php

namespace App\Livewire;

use Livewire\Component;
use Mary\Traits\Toast;

class __CLASS__ extends Component
{
    use Toast;

    public string $search = '';

    public bool $editing = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'member';

    public array $roles = [
        ['id' => 'admin', 'name' => 'Admin'],
        ['id' => 'editor', 'name' => 'Editor'],
        ['id' => 'member', 'name' => 'Member'],
    ];

    public array $headers = [
        ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
        ['key' => 'name', 'label' => 'Name'],
        ['key' => 'email', 'label' => 'Email'],
        ['key' => 'role', 'label' => 'Role'],
    ];

    public array $users = [];

    public function mount(): void
    {
        $this->users = [
            ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'role' => 'admin'],
            ['id' => 2, 'name' => 'Alan Turing', 'email' => 'alan@example.com', 'role' => 'editor'],
            ['id' => 3, 'name' => 'Grace Hopper', 'email' => 'grace@example.com', 'role' => 'member'],
            ['id' => 4, 'name' => 'Linus Torvalds', 'email' => 'linus@example.com', 'role' => 'member'],
        ];
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'email', 'role');
        $this->resetValidation();
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $user = collect($this->users)->firstWhere('id', $id);
        if (!$user) {
            return;
        }

        $this->editingId = $id;
        $this->name = $user['name'];
        $this->email = $user['email'];
        $this->role = $user['role'];
        $this->resetValidation();
        $this->editing = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => 'required|min:2',
            'email' => 'required|email',
            'role' => 'required',
        ]);

        if ($this->editingId) {
            $this->users = array_map(
                fn ($user) => $user['id'] === $this->editingId ? ['id' => $user['id'], ...$data] : $user,
                $this->users
            );
            $this->success('User updated.');
        } else {
            $this->users[] = ['id' => (collect($this->users)->max('id') ?? 0) + 1, ...$data];
            $this->success('User added.');
        }

        $this->editing = false;
    }

    public function delete(int $id): void
    {
        $this->users = array_values(array_filter($this->users, fn ($user) => $user['id'] !== $id));
        $this->warning('User deleted.');
    }

    public function render()
    {
        $rows = collect($this->users)->filter(
            fn ($user) => $this->search === ''
                || str_contains(strtolower($user['name'] . ' ' . $user['email']), strtolower($this->search))
        );

        return view('__VIEW__', ['rows' => $rows->values()]);
    }
}
