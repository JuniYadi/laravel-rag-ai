<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->socialUser = (new SocialiteUser)->map([
        'id' => 'google-12345',
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);
});

it('redirects to google oauth provider', function () {
    Socialite::fake('google');

    $response = $this->get(route('auth.google.redirect'));

    $response->assertRedirect();
});

it('can register a new user via google', function () {
    Socialite::fake('google', $this->socialUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('dashboard'));

    assertDatabaseHas('users', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'provider' => 'google',
        'provider_id' => 'google-12345',
    ]);

    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()->email)->toBe('john@example.com');
});

it('can login an existing google user', function () {
    $user = User::factory()->withSocialProvider('google', 'google-12345')->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    Socialite::fake('google', $this->socialUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);
});

it('links existing email user to google account', function () {
    $user = User::factory()->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    expect($user->provider)->toBeNull()
        ->and($user->provider_id)->toBeNull();

    Socialite::fake('google', $this->socialUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('dashboard'));

    expect($user->fresh()->provider)->toBe('google')
        ->and($user->fresh()->provider_id)->toBe('google-12345');

    expect(auth()->id())->toBe($user->id);
});

it('displays google button on login page', function () {
    $response = $this->get(route('login'));

    $response->assertSuccessful()
        ->assertSee('Google')
        ->assertSee(route('auth.google.redirect'));
});

it('displays google button on register page', function () {
    $response = $this->get(route('register'));

    $response->assertSuccessful()
        ->assertSee('Google')
        ->assertSee(route('auth.google.redirect'));
});
