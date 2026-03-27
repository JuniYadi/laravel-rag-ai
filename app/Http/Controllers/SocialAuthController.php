<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Redirect the user to the Google OAuth provider.
     */
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle the Google OAuth callback.
     *
     * Three scenarios:
     * 1. Existing Google user (matched by provider + provider_id) -> login
     * 2. Existing email user (no provider set) -> link Google account, login
     * 3. New user -> create account with Google data, login
     */
    public function callback()
    {
        $googleUser = Socialite::driver('google')->user();

        // 1. Check if user already registered via Google
        $existingUser = User::where('provider', 'google')
            ->where('provider_id', $googleUser->getId())
            ->first();

        if ($existingUser) {
            Auth::login($existingUser);

            return redirect()->intended(route('dashboard'));
        }

        // 2. Check if user exists with the same email (link account)
        $emailUser = User::where('email', $googleUser->getEmail())->first();

        if ($emailUser) {
            $emailUser->update([
                'provider' => 'google',
                'provider_id' => $googleUser->getId(),
            ]);

            Auth::login($emailUser);

            return redirect()->intended(route('dashboard'));
        }

        // 3. Create new user from Google data
        $newUser = User::create([
            'name' => $googleUser->getName(),
            'email' => $googleUser->getEmail(),
            'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(),
            'provider' => 'google',
            'provider_id' => $googleUser->getId(),
        ]);

        Auth::login($newUser);

        return redirect()->intended(route('dashboard'));
    }
}
