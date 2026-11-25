<?php

namespace Database\Factories;

use App\Domain\Catalogue\Models\Country;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'handle' => 'angler_'.Str::lower(Str::random(8)),
            'display_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= 'password',
            'country_id' => fn () => Country::query()->where('iso2', 'MY')->value('id')
                ?? Country::create(['iso2' => 'MY', 'name' => 'Malaysia', 'default_locale' => 'ms', 'is_launched' => true])->id,
            'locale' => 'en',
            'timezone' => 'Asia/Kuala_Lumpur',
            'remember_token' => Str::random(10),
        ];
    }

    public function consented(): static
    {
        return $this->state(fn () => ['beta_ai_consent' => true, 'beta_ai_consent_at' => now()]);
    }

    public function veteran(): static
    {
        // Older than the new-account quota window.
        return $this->state(fn () => ['created_at' => now()->subDays(10)]);
    }
}
