<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            /*
             * Unique, because the column is — a collision surfaces as a
             * confusing constraint violation in whatever test ran second rather
             * than as a factory problem.
             *
             * And built to pass the app's OWN rule: `fake()->userName()` emits
             * dots and capitals, so a fixture using it produced a user the
             * validation would reject. That failed intermittently, on the runs
             * where faker happened to pick a name with a dot in it.
             */
            'handle' => Str::of(fake()->unique()->userName())
                ->lower()
                ->replaceMatches('/[^a-z0-9_]/', '')
                ->padRight(3, 'x')
                ->limit(20, '')
                ->toString(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * `admin` is not fillable, so this state is the only supported way to make
     * one — including in tests.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'admin' => true,
        ]);
    }
}
