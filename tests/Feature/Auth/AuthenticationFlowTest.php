<?php

use App\Enums\TrashTalkIntensity;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

it('renders the login screen', function () {
    $this->get(route('login'))->assertOk()->assertSee('Welcome back');
});

it('logs a user in with valid credentials', function () {
    $user = User::factory()->create();

    Livewire::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

it('rejects an invalid password', function () {
    $user = User::factory()->create();

    Livewire::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'not-the-password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('registers a new user and fires the Registered event', function () {
    Event::fake();

    Livewire::test('auth.register')
        ->set('name', 'Gunner Stockton')
        ->set('email', 'gunner@example.com')
        ->set('password', 'password-that-passes')
        ->set('password_confirmation', 'password-that-passes')
        ->call('register')
        ->assertHasNoErrors();

    // Registered drives the verification email, which is what makes the
    // MustVerifyEmail contract actually do something.
    Event::assertDispatched(Registered::class);

    $user = User::whereEmail('gunner@example.com')->sole();

    expect($user->admin)->toBeFalse()
        ->and($user->trash_talk_intensity)->toBe(TrashTalkIntensity::LockerRoom);

    $this->assertAuthenticatedAs($user);
});

it('will not register a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test('auth.register')
        ->set('name', 'Someone Else')
        ->set('email', 'taken@example.com')
        ->set('password', 'password-that-passes')
        ->set('password_confirmation', 'password-that-passes')
        ->call('register')
        ->assertHasErrors('email');
});

it('logs a user out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});

it('does not leak whether an email exists on password reset', function () {
    // An unknown address must look exactly like a known one, or the form
    // becomes an account-enumeration oracle.
    Livewire::test('auth.forgot-password')
        ->set('email', 'nobody@example.com')
        ->call('sendPasswordResetLink')
        ->assertHasNoErrors()
        ->assertSet('status', 'If that email is on file, a reset link is on its way.');
});
