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
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PreviewController;





// Route::view('/', 'welcome')->name('welcome');
Route::get('/', Homepage::class)->name('welcome');
Route::redirect('/login', '/')->name('login');
Route::redirect('/register', '/')->name('register');


Route::get('/home', Home::class)->name('home');
// Route::get('/dashboard', Dashboard::class)->name('dashboard');
Route::get('/forgot-password', PasswordReset::class)->name('password.request');
Route::get('/reset-password/{token}', PasswordResetForm::class)->name('password.reset');
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', CodeGenerator::class)->name('dashboard');
    Route::get('/settings', Settings::class)->name('settings');
    
    // API Routes for Preview and Project Management
    Route::prefix('api')->group(function () {
        // Project management
        Route::apiResource('projects', ProjectController::class);
        Route::post('projects/{project}/initialize-preview', [ProjectController::class, 'initializePreview'])->name('projects.initialize-preview');
        Route::get('projects/{project}/stats', [ProjectController::class, 'getStats'])->name('projects.stats');
        
        // Preview management
        Route::post('preview/create', [PreviewController::class, 'createPreview'])->name('preview.create');
        Route::get('preview/{project}/status', [PreviewController::class, 'getPreviewStatus'])->name('preview.status');
        Route::put('preview/update', [PreviewController::class, 'updatePreview'])->name('preview.update');
        Route::delete('preview/{project}/stop', [PreviewController::class, 'stopPreview'])->name('preview.stop');
        Route::get('preview/containers', [PreviewController::class, 'getUserContainers'])->name('preview.containers');
        
        // Admin cleanup (optional)
        Route::post('preview/cleanup', [PreviewController::class, 'cleanupExpiredContainers'])->name('preview.cleanup');
    });
});

Route::get('/auth/{provider}/redirect', [SocialController::class, 'redirect'])
->whereIn('provider', ['google', 'github', 'facebook', 'twitter'])
->name('oauth.redirect');

Route::get('/auth/{provider}/callback', [SocialController::class, 'callback'])
->whereIn('provider', ['google', 'github', 'facebook', 'twitter'])
->name('oauth.callback');