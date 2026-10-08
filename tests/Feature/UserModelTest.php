<?php

use App\Exceptions\Domain\AccountCannotBeDeleted;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;

test('BR-U4: anonymize replaces the personal data and keeps the row', function () {
    $this->freezeSecond();
    $user = User::factory()->withTwoFactor()->create(['name' => 'Ada Lovelace']);

    $user->anonymize();

    expect($user->name)->toBe('Deleted user')
        ->and($user->email)->toBe("deleted-user-{$user->id}@deleted.invalid")
        ->and($user->password)->toBeNull()
        ->and($user->remember_token)->toBeNull()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->anonymized_at->equalTo(now()))->toBeTrue()
        ->and($user->isAnonymized())->toBeTrue()
        ->and($user->isDirty())->toBeTrue();
});

test('BR-U4: the anonymized user is stored with a null password', function () {
    $user = User::factory()->create();

    $user->anonymize();
    $user->save();

    $stored = $user->fresh();

    expect($stored)->not->toBeNull()
        ->and($stored->password)->toBeNull()
        ->and($stored->anonymized_at)->not->toBeNull()
        ->and($stored->isAnonymized())->toBeTrue();
});

test('BR-U4: two anonymized users get different emails', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $first->anonymize();
    $first->save();
    $second->anonymize();
    $second->save();

    expect($first->fresh()->email)->not->toBe($second->fresh()->email);
});

test('a new user is not anonymized', function () {
    $user = User::factory()->create();

    expect($user->fresh()->anonymized_at)->toBeNull()
        ->and($user->isAnonymized())->toBeFalse();
});

test('the anonymized factory state makes an anonymized user', function () {
    $user = User::factory()->anonymized()->create();

    expect($user->fresh()->isAnonymized())->toBeTrue()
        ->and($user->fresh()->name)->toBe('Deleted user')
        ->and($user->fresh()->password)->toBeNull();
});

test('a user with no events and no bookings can be deleted', function () {
    $user = User::factory()->create();

    $user->ensureCanBeDeleted();
})->throwsNoExceptions();

test('BR-U1: a user who organizes a published event that has not started cannot be deleted', function () {
    $user = User::factory()->create();
    Event::factory()->published()->for($user, 'organizer')->create(['starts_at' => now()->addDay()]);

    $user->ensureCanBeDeleted();
})->throws(
    AccountCannotBeDeleted::class,
    'You organize a published event that has not started. Cancel the event before you delete your account.',
);

test('BR-U1: drafts, cancelled events and started events do not block the delete', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    Event::factory()->for($user, 'organizer')->create(['starts_at' => now()->addDay()]);
    Event::factory()->cancelled()->for($user, 'organizer')->create(['starts_at' => now()->addDay()]);
    Event::factory()->published()->for($user, 'organizer')->create(['starts_at' => now()]);
    Event::factory()->published()->for($user, 'organizer')->create(['starts_at' => now()->subDay()]);

    $user->ensureCanBeDeleted();
})->throwsNoExceptions();

test('BR-U2: a user with a confirmed booking for an event that has not started cannot be deleted', function () {
    $user = User::factory()->create();
    $event = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    Booking::factory()->for($event)->for($user, 'attendee')->create();

    $user->ensureCanBeDeleted();
})->throws(
    AccountCannotBeDeleted::class,
    'You have a booking for an event that has not started. Cancel the booking before you delete your account.',
);

test('BR-U2: cancelled bookings and bookings of started events do not block the delete', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    $upcoming = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    $started = Event::factory()->published()->create(['starts_at' => now()]);
    Booking::factory()->cancelled()->for($upcoming)->for($user, 'attendee')->create();
    Booking::factory()->for($started)->for($user, 'attendee')->create();

    $user->ensureCanBeDeleted();
})->throwsNoExceptions();

test('BR-U1: the organizer check comes before the booking check', function () {
    $user = User::factory()->create();
    Event::factory()->published()->for($user, 'organizer')->create(['starts_at' => now()->addDay()]);
    $other = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    Booking::factory()->for($other)->for($user, 'attendee')->create();

    $user->ensureCanBeDeleted();
})->throws(AccountCannotBeDeleted::class, 'You organize a published event');

test('events and bookings of other users do not block the delete', function () {
    $user = User::factory()->create();
    $event = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    Booking::factory()->for($event)->create();

    $user->ensureCanBeDeleted();
})->throwsNoExceptions();
