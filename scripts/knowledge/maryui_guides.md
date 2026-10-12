# MaryUI usage guides

Each `##` section is one retrievable guide. The `keywords:` line drives retrieval;
`components:` lists the tags the guide covers. Snippets are verified against the
MaryUI version in `maryui_reference.json` - keep them in sync when upgrading.

## Livewire component conventions
keywords: livewire component class render view property method toast validation always
components: x-toast

- Class lives in `App\Livewire`, extends `Livewire\Component`, file name = class name.
- `render()` returns `view('livewire.<kebab-name>')`, e.g. `UserTable` -> `livewire.user-table`.
- Add `use Mary\Traits\Toast;` and call `$this->success('Saved')`, `$this->error('...')`,
  `$this->info('...')`, `$this->warning('...')`. The layout already renders `<x-toast />`.
- Validation errors render automatically under MaryUI inputs. Do not add `@error` blocks.
- Every `wire:click` / `wire:submit` target must exist as a public method on the class.
- Every `wire:model` target must exist as a public property.
- The Blade view must have exactly one root element.
- There is no database in the preview. Seed demo data in `mount()` or as property defaults
  using plain arrays or `collect([...])`; never query Eloquent models.

## Forms
keywords: form input field submit validate register login signup contact email password
components: x-form x-input x-password x-textarea x-select x-checkbox x-toggle x-radio x-datetime

```blade
<x-form wire:submit="save">
    <x-input label="Name" wire:model="name" icon="o-user" />
    <x-input label="Email" wire:model="email" type="email" />
    <x-password label="Password" wire:model="password" right />
    <x-textarea label="Bio" wire:model="bio" rows="4" hint="Max 500 chars" />
    <x-select label="Role" wire:model="role" :options="$roles" placeholder="Choose a role" />
    <x-checkbox label="Accept terms" wire:model="terms" />
    <x-toggle label="Email notifications" wire:model="notify" right />
    <x-datetime label="Birth date" wire:model="birthday" />

    <x-slot:actions>
        <x-button label="Cancel" wire:click="cancel" />
        <x-button label="Save" class="btn-primary" type="submit" spinner="save" />
    </x-slot:actions>
</x-form>
```

`x-select` / `x-radio` options are arrays of `['id' => ..., 'name' => ...]`
(override keys with `option-value` / `option-label`).

```php
public array $roles = [['id' => 'admin', 'name' => 'Admin'], ['id' => 'editor', 'name' => 'Editor']];

public function save(): void
{
    $this->validate(['name' => 'required', 'email' => 'required|email']);
    $this->success('Saved.');
}
```

## Tables
keywords: table list data grid rows columns users orders records sort paginate crud
components: x-table

`x-table` takes `:headers` and `:rows`. Customize cells with `@scope('cell_<key>', $row)` and
row actions with `@scope('actions', $row)`. There is no `<x-table.th>` / `<x-table.tr>`.

```php
public array $headers = [
    ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
    ['key' => 'name', 'label' => 'Name'],
    ['key' => 'email', 'label' => 'Email'],
];

public function users()
{
    return collect([
        ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'active' => true],
        ['id' => 2, 'name' => 'Alan Turing', 'email' => 'alan@example.com', 'active' => false],
    ]);
}

public function render()
{
    return view('livewire.user-table', ['users' => $this->users()]);
}
```

```blade
<x-table :headers="$headers" :rows="$users" striped>
    @scope('cell_name', $user)
        <span class="font-semibold">{{ $user['name'] }}</span>
    @endscope
    @scope('actions', $user)
        <x-button icon="o-trash" wire:click="delete({{ $user['id'] }})" wire:confirm="Delete?" spinner class="btn-ghost btn-sm" />
    @endscope
</x-table>
```

## Cards and stats
keywords: card stat statistic metric kpi dashboard overview summary widget count revenue
components: x-card x-stat

```blade
<div class="grid gap-4 lg:grid-cols-3">
    <x-stat title="Users" value="1,204" icon="o-users" description="+12% this month" />
    <x-stat title="Revenue" value="$48,300" icon="o-currency-dollar" color="text-success" />
    <x-stat title="Orders" value="312" icon="o-shopping-cart" />
</div>

<x-card title="Recent activity" subtitle="Last 7 days" separator shadow class="mt-6">
    ...
    <x-slot:menu>
        <x-button icon="o-arrow-path" class="btn-ghost btn-sm" wire:click="refresh" />
    </x-slot:menu>
    <x-slot:actions>
        <x-button label="View all" link="/activity" />
    </x-slot:actions>
</x-card>
```

## Page header
keywords: header title page heading toolbar search actions
components: x-header

```blade
<x-header title="Users" subtitle="Manage your team" separator>
    <x-slot:middle class="!justify-end">
        <x-input icon="o-magnifying-glass" placeholder="Search..." wire:model.live.debounce="search" clearable />
    </x-slot:middle>
    <x-slot:actions>
        <x-button label="Add user" icon="o-plus" class="btn-primary" wire:click="create" />
    </x-slot:actions>
</x-header>
```

## Modals and drawers
keywords: modal dialog popup confirm drawer sidebar panel slide overlay
components: x-modal x-drawer

Open/close by toggling a boolean public property bound with `wire:model`.

```blade
<x-button label="New user" wire:click="$set('showModal', true)" />

<x-modal wire:model="showModal" title="New user" separator>
    <x-form wire:submit="store">
        <x-input label="Name" wire:model="name" />
        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showModal', false)" />
            <x-button label="Create" class="btn-primary" type="submit" spinner="store" />
        </x-slot:actions>
    </x-form>
</x-modal>

<x-drawer wire:model="showFilters" title="Filters" right separator with-close-button class="lg:w-1/3">
    ...
</x-drawer>
```

```php
public bool $showModal = false;
public bool $showFilters = false;
```

## Tabs
keywords: tabs tab sections switch panels settings profile
components: x-tabs x-tab

```blade
<x-tabs wire:model="selectedTab">
    <x-tab name="profile" label="Profile" icon="o-user">...</x-tab>
    <x-tab name="security" label="Security" icon="o-lock-closed">...</x-tab>
</x-tabs>
```

```php
public string $selectedTab = 'profile';
```

## Sidebar layout and navigation
keywords: dashboard sidebar navigation nav menu admin panel multi-page layout app shell
components: x-main x-nav x-menu x-menu-item x-menu-sub x-menu-separator

The preview ships `<x-layouts.app-with-sidebar>` (sidebar + mobile nav). Use it only for
dashboards / admin areas, one Livewire component per page. Each page's route is
`/<kebab-name>` of its class (`UsersPage` -> `/users-page`), so menu links must use those.

```blade
<x-layouts.app-with-sidebar>
    <x-header title="Orders" separator />
    ...
</x-layouts.app-with-sidebar>
```

To build your own menu inside a page:

```blade
<x-menu activate-by-route>
    <x-menu-item title="Dashboard" icon="o-home" link="/admin-dashboard" />
    <x-menu-sub title="Settings" icon="o-cog-6-tooth">
        <x-menu-item title="Profile" icon="o-user" link="/profile-settings" />
    </x-menu-sub>
    <x-menu-separator />
    <x-menu-item title="Logout" icon="o-power" wire:click="logout" />
</x-menu>
```

## Buttons, badges and dropdowns
keywords: button action badge label tag dropdown menu more options link icon
components: x-button x-badge x-dropdown x-icon

```blade
<x-button label="Save" icon="o-check" class="btn-primary" wire:click="save" spinner />
<x-button icon="o-pencil" class="btn-circle btn-ghost" tooltip="Edit" wire:click="edit(1)" />
<x-button label="Docs" link="/docs" icon-right="o-arrow-top-right-on-square" external />
<x-badge value="Active" class="badge-success" />

<x-dropdown label="Actions" class="btn-ghost" right>
    <x-menu-item title="Archive" icon="o-archive-box" wire:click="archive" />
    <x-menu-item title="Delete" icon="o-trash" wire:click="delete" />
</x-dropdown>

<x-icon name="o-envelope" class="w-5 h-5 text-primary" />
```

## Lists, avatars and alerts
keywords: list item avatar profile contacts feed notification alert warning message empty state
components: x-list-item x-avatar x-alert

`x-list-item` reads keys from `:item` (`value` defaults to `name`, `avatar` to `avatar`).

```blade
@foreach($contacts as $contact)
    <x-list-item :item="$contact" sub-value="email" link="/contacts">
        <x-slot:actions>
            <x-button icon="o-chat-bubble-left" class="btn-ghost btn-sm" wire:click="message({{ $contact['id'] }})" />
        </x-slot:actions>
    </x-list-item>
@endforeach

<x-avatar image="https://i.pravatar.cc/80?u=1" title="Ada Lovelace" subtitle="Admin" class="!w-10" />

<x-alert title="No orders yet" description="Orders will appear here." icon="o-inbox" class="alert-info" />
```

## Progress, steps and timeline
keywords: progress steps wizard onboarding checkout stepper timeline history status
components: x-progress x-steps x-step x-timeline-item

```blade
<x-progress value="40" max="100" class="progress-primary h-2" />

<x-steps wire:model="step" class="border-y border-base-content/10 my-5 py-5">
    <x-step step="1" text="Account">...</x-step>
    <x-step step="2" text="Payment">...</x-step>
    <x-step step="3" text="Done">...</x-step>
</x-steps>

<x-timeline-item title="Order placed" subtitle="10/11/2026" first />
<x-timeline-item title="Shipped" subtitle="10/12/2026" last pending />
```

```php
public int $step = 1;
```

## Collapsible content
keywords: accordion collapse faq expand toggle details questions
components: x-accordion x-collapse

```blade
<x-accordion wire:model="openFaq">
    <x-collapse name="shipping">
        <x-slot:heading>How long does shipping take?</x-slot:heading>
        <x-slot:content>3-5 business days.</x-slot:content>
    </x-collapse>
</x-accordion>
```

## Choices and tags
keywords: multi select choices autocomplete search tags chips combobox
components: x-choices x-choices-offline x-tags

```blade
<x-choices-offline label="Skills" wire:model="skillIds" :options="$skills" searchable />
<x-choices label="Owner" wire:model="ownerId" :options="$owners" single />
<x-tags label="Keywords" wire:model="keywords" hint="Press enter to add" />
```

```php
public array $skillIds = [];
public ?int $ownerId = null;
public array $keywords = [];
```

## File uploads
keywords: file upload image avatar photo attachment document
components: x-file

Requires `use Livewire\WithFileUploads;` on the class.

```blade
<x-file wire:model="photo" label="Photo" accept="image/png, image/jpeg" />
```

```php
use Livewire\WithFileUploads;
public $photo;
```
