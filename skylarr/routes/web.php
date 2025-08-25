<?php

use App\Livewire\Welcome;
use App\Livewire\Home;
use App\Livewire\Homepage;
use App\Livewire\Dashboard;
use App\Livewire\PasswordReset;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;





// Route::view('/', 'welcome')->name('welcome');
Route::get('/', Homepage::class)->name('welcome');
Route::view('/login', 'auth.login')->name('login');
Route::view('/register', 'auth.register')->name('register');


Route::get('/home', Home::class)->name('home');
Route::get('/dashboard', Dashboard::class)->name('dashboard');
Route::get('/forgot-password', PasswordReset::class)->name('password.request');