<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'token_hash' => hash('sha256', Str::random(48)),
            'expires_at' => now()->addDays(14),
            'sent_count' => 1,
            'last_sent_at' => now(),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['accepted_at' => now()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
