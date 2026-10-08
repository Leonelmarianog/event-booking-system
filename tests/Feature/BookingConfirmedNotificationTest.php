<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->attendee = User::factory()->create(['name' => 'Ada Lovelace']);

    $event = Event::factory()->published()->create([
        'title' => 'Laravel Meetup',
        'venue' => 'Main Hall',
        'starts_at' => CarbonImmutable::parse('2026-10-12 18:00:00', 'UTC'),
    ]);

    $this->booking = Booking::factory()->for($event)->for($this->attendee, 'attendee')
        ->create(['quantity' => 2]);
});

test('BR-N1: the booking confirmed email has the booking details', function () {
    $mail = (new BookingConfirmed($this->booking))->toMail($this->attendee);

    expect($mail->subject)->toBe('Booking confirmed: Laravel Meetup')
        ->and($mail->greeting)->toBe('Hello Ada Lovelace,')
        ->and($mail->introLines)->toBe([
            'Your booking for Laravel Meetup is confirmed.',
            "Reference: {$this->booking->reference}",
            'Seats: 2',
            'Starts: Mon 12 Oct 2026, 18:00 UTC',
            'Venue: Main Hall',
        ])
        ->and($mail->actionText)->toBe('View event')
        ->and($mail->actionUrl)->toBe(route('events.show', $this->booking->event));
});

test('BR-N6: the notification is queued after the commit and goes by mail', function () {
    $notification = new BookingConfirmed($this->booking);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->afterCommit)->toBeTrue()
        ->and($notification->via($this->attendee))->toBe(['mail']);
});

test('the queued email is tried 3 times with a backoff', function () {
    $job = new SendQueuedNotifications($this->attendee, new BookingConfirmed($this->booking), ['mail']);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60]);
});

test('BR-N1: the start time is in UTC when the database session uses another time zone', function () {
    DB::statement("SET LOCAL TIME ZONE 'America/New_York'");

    $mail = (new BookingConfirmed($this->booking->fresh()))->toMail($this->attendee);

    expect($mail->introLines)->toContain('Starts: Mon 12 Oct 2026, 18:00 UTC');
});
