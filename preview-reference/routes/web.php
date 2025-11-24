<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\Demodash;
use App\Livewire\Demousers;
use App\Livewire\Demosettings;
use App\Livewire\Demologout;
// Routes will be added dynamically by the code generation system
Route::get('/', Demodash::class)->name('home');
Route::get('/users', Demousers::class)->name('users');
Route::get('/settings', Demosettings::class)->name('settings');
Route::get('/logout', Demologout::class)->name('logout');