<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The data of the concurrency check (scripts/check-concurrency.sh): a published event
 * with 1 seat and 20 users. It uses no factories, because the runtime image has no
 * Faker. Each run has its own tag, so that the script can delete only its own rows.
 */
class ConcurrencyCheckSeeder extends Seeder
{
    /**
     * The number of users who try to book the last seat.
     */
    public const ATTENDEES = 20;

    /**
     * Seed the data and print the tag and the event ID for the script.
     */
    public function run(): void
    {
        $tag = now()->format('YmdHis').Str::lower(Str::random(4));
        $password = Hash::make('password');

        $organizer = User::create([
            'name' => 'Concurrency organizer',
            'email' => "concurrency-{$tag}-organizer@example.test",
            'password' => $password,
        ]);

        foreach (range(1, self::ATTENDEES) as $number) {
            User::create([
                'name' => "Concurrency attendee {$number}",
                'email' => "concurrency-{$tag}-{$number}@example.test",
                'password' => $password,
            ]);
        }

        $event = new Event;
        $event->organizer_id = $organizer->id;
        $event->title = "Concurrency check {$tag}";
        $event->description = 'An event with one seat for the concurrency check.';
        $event->venue = 'Nowhere';
        $event->starts_at = now()->addDay();
        $event->capacity = 1;
        $event->seats_available = 1;
        $event->status = EventStatus::Published;
        $event->published_at = now();
        $event->save();

        $this->command->line("CONCURRENCY_TAG={$tag}");
        $this->command->line("CONCURRENCY_EVENT_ID={$event->id}");
    }
}
