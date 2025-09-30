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
        $oauthUser = Socialite::driver($provider)->user();

        $user = User::firstOrCreate(
            ['email' => $oauthUser->email],
            [
                'name' => $oauthUser->name,
                'password' => Hash::make(Str::random(10)),
            ]
        );
        
        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
