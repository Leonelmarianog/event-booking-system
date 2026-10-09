<?php

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();

    $this->seed(DatabaseSeeder::class);
});

test('the named accounts can log in with the password "password"', function () {
    $emails = ['organizer@example.com', 'attendee@example.com', 'admin@example.com'];

    foreach ($emails as $email) {
        $user = User::where('email', $email)->firstOrFail();

        expect(Hash::check('password', $user->password))->toBeTrue()
            ->and($user->email_verified_at)->not->toBeNull();
    }

    expect(User::where('email', 'admin@example.com')->first()->is_admin)->toBeTrue()
        ->and(User::where('is_admin', true)->count())->toBe(1);
});

test('the seeder makes 186 users', function () {
    expect(User::count())->toBe(186);
});

test('the events are in every state', function () {
    expect(Event::query()->published()->upcoming()->count())->toBe(13)
        ->and(Event::where('status', EventStatus::Draft)->count())->toBe(2)
        ->and(Event::query()->published()->where('starts_at', '<', now())->count())->toBe(1)
        ->and(Event::where('status', EventStatus::Cancelled)->count())->toBe(1)
        ->and(Event::query()->published()->upcoming()->where('seats_available', 0)->count())->toBe(1);
});

test('the available seats of each event match its confirmed bookings', function () {
    foreach (Event::with('bookings')->get() as $event) {
        $seatsBooked = $event->bookings
            ->where('status', BookingStatus::Confirmed)
            ->sum('quantity');

        expect($event->seats_available)->toBe($event->capacity - $seatsBooked, $event->title);
    }
});

test('the bookings of a cancelled event are cancelled with it', function () {
    $event = Event::where('status', EventStatus::Cancelled)->firstOrFail();

    expect($event->bookings)->not->toBeEmpty()
        ->and($event->bookings->every(fn (Booking $booking) => $booking->wasCancelledWithEvent()))->toBeTrue();
});

test('the attendee account has bookings in every state', function () {
    $attendee = User::where('email', 'attendee@example.com')->firstOrFail();
    $bookings = Booking::where('user_id', $attendee->id)->with('event')->get();

    $upcomingConfirmed = $bookings->filter(fn (Booking $booking) => $booking->isConfirmed() && ! $booking->event->hasStarted());
    $past = $bookings->filter(fn (Booking $booking) => $booking->event->hasStarted());
    $cancelledByAttendee = $bookings->filter(fn (Booking $booking) => $booking->isCancelled() && ! $booking->wasCancelledWithEvent());
    $cancelledWithEvent = $bookings->filter(fn (Booking $booking) => $booking->wasCancelledWithEvent());

    expect($upcomingConfirmed)->toHaveCount(3)
        ->and($past)->toHaveCount(1)
        ->and($cancelledByAttendee)->toHaveCount(1)
        ->and($cancelledWithEvent)->toHaveCount(1);
});

test('the organizer account has upcoming, past and draft events with attendees', function () {
    $organizer = User::where('email', 'organizer@example.com')->firstOrFail();
    $events = Event::where('organizer_id', $organizer->id)->get();

    expect($events->filter(fn (Event $event) => $event->isPublished() && ! $event->hasStarted()))->toHaveCount(5)
        ->and($events->filter(fn (Event $event) => $event->hasStarted()))->toHaveCount(1)
        ->and($events->filter(fn (Event $event) => $event->isDraft()))->toHaveCount(2)
        ->and($events->filter(fn (Event $event) => $event->isCancelled()))->toHaveCount(1)
        ->and(Event::where('title', 'Laravel Meetup Lisbon')->firstOrFail()->hasBookingBy(User::where('email', 'attendee@example.com')->firstOrFail()))->toBeTrue();
});

test('the generated organizers organize events too', function () {
    $organizer = User::where('email', 'organizer@example.com')->firstOrFail();

    expect(Event::where('organizer_id', '!=', $organizer->id)->distinct()->count('organizer_id'))->toBe(3);
});

test('the events page has a second page', function () {
    $this->get(route('events.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('events.data', 1));
});

test('the attendee list of the biggest event has four pages', function () {
    $admin = User::where('email', 'admin@example.com')->firstOrFail();
    $event = Event::where('title', 'Jazz Night at the Harbour')->firstOrFail();

    $this->actingAs($admin)
        ->get(route('events.attendees.index', ['event' => $event, 'page' => 3]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('attendees.data', 50));

    $this->actingAs($admin)
        ->get(route('events.attendees.index', ['event' => $event, 'page' => 4]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('attendees.data', 25));
});

test('the events do not all start at the same hour', function () {
    $hours = Event::all()->map(fn (Event $event) => $event->starts_at->format('H:i'))->unique();

    expect($hours->count())->toBeGreaterThan(5);
});

test('the seeder sends no email and leaves no reminder due', function () {
    Notification::assertNothingSent();

    expect(Event::query()->dueForReminder()->count())->toBe(0);
});

test('the start times are the times of day in Lisbon, where the events take place', function () {
    $startsAt = fn (string $title) => Event::where('title', $title)->firstOrFail()
        ->starts_at->setTimezone('Europe/Lisbon')->format('H:i');

    expect($startsAt('Jazz Night at the Harbour'))->toBe('21:00')
        ->and($startsAt('Yoga in the Park'))->toBe('08:30')
        ->and($startsAt('JavaScript Meetup Lisbon'))->toBe('18:30')
        ->and($startsAt('Street Food Market'))->toBe('12:00');
});
