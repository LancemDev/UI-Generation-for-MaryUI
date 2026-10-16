<?php

use App\Livewire\Demodash;
use App\Livewire\Demologout;
use App\Livewire\Demosettings;
use App\Livewire\Demousers;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', Demodash::class)->name('home');
Route::get('/users', Demousers::class)->name('users');
Route::get('/settings', Demosettings::class)->name('settings');
Route::get('/logout', Demologout::class)->name('logout');

// Every Livewire component is served at /<kebab-name>, e.g. UserTable -> /user-table.
// Skylarr writes generated components into app/Livewire and relies on this, so
// adding a component needs no route edits and no cache clearing.
foreach (glob(app_path('Livewire/*.php')) as $file) {
    $class = basename($file, '.php');
    $slug = Str::kebab($class);

    Route::get("/{$slug}", "App\\Livewire\\{$class}")->name("generated.{$slug}");
}
