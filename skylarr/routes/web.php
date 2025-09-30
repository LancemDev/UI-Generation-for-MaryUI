<?php

use App\Livewire\Welcome;
use App\Livewire\Home;
use App\Livewire\Homepage;
use App\Livewire\Dashboard;
use App\Livewire\PasswordReset;
use App\Livewire\PasswordResetForm;
use App\Livewire\CodeGenerator;
use App\Livewire\Settings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

use App\Http\Controllers\AuthSocialController as SocialController;





// Route::view('/', 'welcome')->name('welcome');
Route::get('/', Homepage::class)->name('welcome');
Route::view('/login', 'auth.login')->name('login');
Route::view('/register', 'auth.register')->name('register');


Route::get('/home', Home::class)->name('home');
// Route::get('/dashboard', Dashboard::class)->name('dashboard');
Route::get('/forgot-password', PasswordReset::class)->name('password.request');
Route::get('/reset-password/{token}', PasswordResetForm::class)->name('password.reset');
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', CodeGenerator::class)->name('dashboard');
    Route::get('/settings', Settings::class)->name('settings');
});

Route::get('/auth/{provider}/redirect', [SocialController::class, 'redirect'])
->whereIn('provider', ['google', 'github', 'facebook', 'twitter'])
->name('oauth.redirect');

Route::get('/auth/{provider}/callback', [SocialController::class, 'callback'])
->whereIn('provider', ['google', 'github', 'facebook', 'twitter'])
->name('oauth.callback');