<?php

use App\Enums\ContentRating;
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
        ->set('first_name', 'Gunner')
        ->set('last_name', 'Stockton')
        ->set('handle', 'gunner11')
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
        ->and($user->first_name)->toBe('Gunner')
        ->and($user->last_name)->toBe('Stockton')
        ->and($user->handle)->toBe('gunner11')
        // Plenty of places just want to print a person, so `name` still works.
        ->and($user->name)->toBe('Gunner Stockton')
        // Untouched by the form, so the default is what lands.
        ->and($user->content_rating)->toBe(ContentRating::Pg13);

    $this->assertAuthenticatedAs($user);
});

it('will not register a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test('auth.register')
        ->set('first_name', 'Someone')
        ->set('last_name', 'Else')
        ->set('handle', 'someoneelse')
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

describe('handle', function () {
    $fill = fn ($component) => $component
        ->set('first_name', 'Gunner')
        ->set('last_name', 'Stockton')
        ->set('email', 'gunner@example.com')
        ->set('password', 'password-that-passes')
        ->set('password_confirmation', 'password-that-passes');

    it('is required', function () use ($fill) {
        $fill(Livewire::test('auth.register'))->call('register')->assertHasErrors('handle');
    });

    it('will not take one that is already claimed', function () use ($fill) {
        User::factory()->create(['handle' => 'gunner11']);

        $fill(Livewire::test('auth.register'))
            ->set('handle', 'gunner11')
            ->call('register')
            ->assertHasErrors('handle');
    });

    it('will not take one that differs only in case', function () use ($fill) {
        /*
         * `@Gunner11` and `@gunner11` reading as two people is exactly the
         * confusion a handle exists to prevent. The unique index sits on a
         * case-insensitive collation, so the database enforces this even if a
         * future caller skips the form.
         */
        User::factory()->create(['handle' => 'gunner11']);

        $fill(Livewire::test('auth.register'))
            ->set('handle', 'GUNNER11')
            ->call('register')
            ->assertHasErrors('handle');
    });

    it('strips what cannot be typed in a mention as you go', function () {
        // Corrected while typing rather than rejected afterwards — a capital
        // should not become an error message to read and fix.
        Livewire::test('auth.register')
            ->set('handle', 'Gunner Stockton!')
            ->assertSet('handle', 'gunnerstockton');
    });

    it('rejects one that is too short', function () use ($fill) {
        $fill(Livewire::test('auth.register'))
            ->set('handle', 'gs')
            ->call('register')
            ->assertHasErrors('handle');
    });
});

describe('content rating', function () {
    it('starts on PG-13 rather than blank', function () {
        // A preference with a sensible middle. An unset radio group reads as a
        // decision you must research before you are allowed to sign up.
        Livewire::test('auth.register')->assertSet('content_rating', ContentRating::Pg13->value);
    });

    it('stores the chosen rating', function () {
        Livewire::test('auth.register')
            ->set('first_name', 'Gunner')
            ->set('last_name', 'Stockton')
            ->set('handle', 'gunner11')
            ->set('email', 'gunner@example.com')
            ->set('password', 'password-that-passes')
            ->set('password_confirmation', 'password-that-passes')
            ->set('content_rating', ContentRating::R->value)
            ->call('register')
            ->assertHasNoErrors();

        expect(User::whereEmail('gunner@example.com')->sole()->content_rating)
            ->toBe(ContentRating::R);
    });

    it('refuses a rating that is not one of the three', function () {
        Livewire::test('auth.register')
            ->set('first_name', 'Gunner')
            ->set('last_name', 'Stockton')
            ->set('handle', 'gunner11')
            ->set('email', 'gunner@example.com')
            ->set('password', 'password-that-passes')
            ->set('password_confirmation', 'password-that-passes')
            ->set('content_rating', 'nc17')
            ->call('register')
            ->assertHasErrors('content_rating');
    });
});
