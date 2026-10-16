<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class AuthSocialController extends Controller
{
    public function redirect(string $provider)
    {
        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider)
    {
        try {
            $oauthUser = Socialite::driver($provider)->user();
        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            // Session state mismatch - redirect back to login
            return redirect()->route('welcome')->with('error', 'Authentication failed. Please try again.');
        }

        $user = User::firstOrCreate(
            ['email' => $oauthUser->email],
            [
                'name' => $oauthUser->name ?? $oauthUser->nickname ?? 'User',
                'password' => Hash::make(Str::random(10)),
                'oauth_provider' => $provider,
                'oauth_provider_id' => $oauthUser->id,
                'oauth_avatar' => $oauthUser->avatar ?? null,
            ]
        );
        // If existing user, backfill provider columns if empty
        if (!$user->oauth_provider || !$user->oauth_provider_id) {
            $user->oauth_provider = $provider;
            $user->oauth_provider_id = $oauthUser->id;
            $user->oauth_avatar = $oauthUser->avatar ?? $user->oauth_avatar;
            $user->save();
        }
        
        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
