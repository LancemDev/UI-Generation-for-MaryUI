<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Container maintenance affects every user's previews, so it is limited to
        // the emails listed in SKYLARR_ADMIN_EMAILS.
        Gate::define('admin', fn (User $user) => in_array(
            strtolower($user->email),
            array_map('strtolower', config('skylarr.admin_emails', [])),
            true
        ));
    }
}
