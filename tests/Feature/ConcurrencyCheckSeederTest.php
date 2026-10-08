<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

test('the seeder makes a published event with 1 seat and 20 users who can log in', function () {
    Artisan::call('db:seed', ['--class' => 'ConcurrencyCheckSeeder', '--no-interaction' => true]);
    $output = Artisan::output();

    preg_match('/CONCURRENCY_TAG=([a-z0-9]+)/', $output, $tag);
    preg_match('/CONCURRENCY_EVENT_ID=(\d+)/', $output, $eventId);

    $event = Event::findOrFail((int) $eventId[1]);
    $users = User::where('email', 'like', "concurrency-{$tag[1]}-%")->get();
    $attendees = $users->reject(fn (User $user) => $user->is($event->organizer));

    expect($event->status)->toBe(EventStatus::Published)
        ->and($event->capacity)->toBe(1)
        ->and($event->seats_available)->toBe(1)
        ->and($event->isBookable())->toBeTrue()
        ->and($event->organizer->email)->toBe("concurrency-{$tag[1]}-organizer@example.test")
        ->and($attendees)->toHaveCount(20)
        ->and(Hash::check('password', $attendees->first()->password))->toBeTrue();
});

test('each run of the seeder has its own tag', function () {
    Artisan::call('db:seed', ['--class' => 'ConcurrencyCheckSeeder', '--no-interaction' => true]);
    $first = Artisan::output();

    $this->travel(1)->seconds();

    Artisan::call('db:seed', ['--class' => 'ConcurrencyCheckSeeder', '--no-interaction' => true]);
    $second = Artisan::output();

    preg_match('/CONCURRENCY_TAG=([a-z0-9]+)/', $first, $firstTag);
    preg_match('/CONCURRENCY_TAG=([a-z0-9]+)/', $second, $secondTag);

    expect($firstTag[1])->not->toBe($secondTag[1])
        ->and(User::count())->toBe(42);
});
