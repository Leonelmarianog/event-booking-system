<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state: a future draft with all seats available.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organizer_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'venue' => fake()->city(),
            'starts_at' => now()->addDays(fake()->numberBetween(1, 60))->startOfHour(),
            'capacity' => 50,
            'seats_available' => fn (array $attributes) => $attributes['capacity'],
            'status' => EventStatus::Draft,
            'published_at' => null,
        ];
    }

    /**
     * Indicate that the event is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * Indicate that the event is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Cancelled,
        ]);
    }

    /**
     * Indicate that the event started one hour ago.
     */
    public function started(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subHour(),
        ]);
    }
}
