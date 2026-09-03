<?php

namespace Database\Factories;

use App\Models\Feedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    /**
     * Par défaut : une impression sans réponse — l'état le plus fréquent, et celui qui
     * sert de dénominateur au test A/B/C.
     */
    public function definition(): array
    {
        return [
            'sentiment' => null,
            'variant' => fake()->randomElement(['a', 'b', 'c']),
            'audience' => Feedback::AUDIENCE_DEMO,
            'trigger' => Feedback::TRIGGER_SESSION,
            'context' => ['route' => 'filament.admin.pages.dashboard'],
        ];
    }

    public function answered(string $sentiment = Feedback::SENTIMENT_POSITIVE): static
    {
        return $this->state(fn () => ['sentiment' => $sentiment]);
    }

    public function variant(string $variant): static
    {
        return $this->state(fn () => ['variant' => $variant]);
    }
}
