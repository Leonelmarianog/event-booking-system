<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Exceptions\Domain\AlreadyBooked;
use App\Exceptions\Domain\CapacityBelowBookedSeats;
use App\Exceptions\Domain\EventHasStarted;
use App\Exceptions\Domain\EventNotBookable;
use App\Exceptions\Domain\InvalidStateTransition;
use App\Exceptions\Domain\NotEnoughSeats;
use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organizer_id
 * @property string $title
 * @property string $description
 * @property string $venue
 * @property CarbonImmutable $starts_at
 * @property int $capacity
 * @property int $seats_available
 * @property EventStatus $status
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $reminder_sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $organizer
 * @property-read Collection<int, Booking> $bookings
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organizer_id' => 'integer',
            'starts_at' => 'datetime',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'status' => EventStatus::class,
        ];
    }

    /**
     * The user who created the event and manages it.
     *
     * @return BelongsTo<User, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    /**
     * The bookings of the event, in all statuses.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Only published events.
     *
     * @param  Builder<Event>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', EventStatus::Published);
    }

    /**
     * Only events that have not started.
     *
     * @param  Builder<Event>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where('starts_at', '>', now());
    }

    /**
     * Published events that start in the next 24 hours and have no reminder yet
     * (BR-N4, BR-N5).
     *
     * @param  Builder<Event>  $query
     */
    #[Scope]
    protected function dueForReminder(Builder $query): void
    {
        $query->published()
            ->upcoming()
            ->where('starts_at', '<=', now()->addDay())
            ->whereNull('reminder_sent_at');
    }

    /**
     * Whether the given user is the organizer of the event.
     */
    public function isOrganizedBy(User $user): bool
    {
        return $this->organizer_id === $user->id;
    }

    /**
     * Whether the given user has a booking for the event, in any status (BR-E16).
     */
    public function hasBookingBy(User $user): bool
    {
        return $this->bookings()->where('user_id', $user->id)->exists();
    }

    /**
     * An event has started when its start time is now or in the past.
     */
    public function hasStarted(): bool
    {
        return ! $this->starts_at->isFuture();
    }

    /**
     * Whether the event is a draft.
     */
    public function isDraft(): bool
    {
        return $this->status === EventStatus::Draft;
    }

    /**
     * Whether the event is published.
     */
    public function isPublished(): bool
    {
        return $this->status === EventStatus::Published;
    }

    /**
     * Whether the event is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === EventStatus::Cancelled;
    }

    /**
     * The number of seats that attendees have booked.
     */
    public function seatsBooked(): int
    {
        return $this->capacity - $this->seats_available;
    }

    /**
     * Whether the event is published, has not started and has available seats (BR-B2).
     */
    public function isBookable(): bool
    {
        return $this->isPublished() && ! $this->hasStarted() && $this->seats_available > 0;
    }

    /**
     * Whether the event is published, has not started and has no available seats.
     */
    public function isSoldOut(): bool
    {
        return $this->isPublished() && ! $this->hasStarted() && $this->seats_available === 0;
    }

    /**
     * The confirmed booking of the given user for the event, if any.
     */
    public function confirmedBookingBy(User $user): ?Booking
    {
        return $this->bookings()
            ->where('user_id', $user->id)
            ->where('status', BookingStatus::Confirmed)
            ->first();
    }

    /**
     * Whether the given user can book seats now (BR-B2, BR-B3, BR-B4). The page uses it to
     * show the booking form.
     */
    public function canBeBookedBy(User $user): bool
    {
        return $this->isBookable()
            && ! $this->isOrganizedBy($user)
            && $this->confirmedBookingBy($user) === null;
    }

    /**
     * Book seats for the given user (BR-B2 to BR-B4, BR-B6, BR-B7). It decreases the
     * available seats and returns a new confirmed booking. It saves nothing.
     *
     * @throws EventNotBookable
     * @throws AlreadyBooked
     * @throws NotEnoughSeats
     */
    public function reserve(User $attendee, int $quantity): Booking
    {
        if (! $this->isPublished()) {
            throw EventNotBookable::notPublished();
        }

        if ($this->hasStarted()) {
            throw EventNotBookable::hasStarted();
        }

        if ($this->isOrganizedBy($attendee)) {
            throw EventNotBookable::ownEvent();
        }

        if ($this->confirmedBookingBy($attendee) !== null) {
            throw new AlreadyBooked;
        }

        if ($quantity > $this->seats_available) {
            throw new NotEnoughSeats($this->seats_available);
        }

        $this->seats_available -= $quantity;

        $booking = new Booking;
        $booking->event_id = $this->id;
        $booking->user_id = $attendee->id;
        $booking->quantity = $quantity;
        $booking->status = BookingStatus::Confirmed;

        return $booking;
    }

    /**
     * Give back seats of a cancelled booking (BR-B11). The available seats never go above
     * the capacity. It does not save the event.
     */
    public function releaseSeats(int $quantity): void
    {
        $this->seats_available = min($this->capacity, $this->seats_available + $quantity);
    }

    /**
     * Change the capacity and the available seats by the same number (BR-E6, BR-E7, BR-E8).
     *
     * @throws CapacityBelowBookedSeats
     */
    public function changeCapacity(int $capacity): void
    {
        $seatsBooked = $this->seatsBooked();

        if ($capacity < $seatsBooked) {
            throw new CapacityBelowBookedSeats($seatsBooked);
        }

        $this->seats_available += $capacity - $this->capacity;
        $this->capacity = $capacity;
    }

    /**
     * Whether the event can be published now (BR-E10). The page uses it to show the
     * publish button.
     */
    public function canBePublished(): bool
    {
        return $this->status->canTransitionTo(EventStatus::Published) && ! $this->hasStarted();
    }

    /**
     * Publish the event (BR-E10). It does not save the event.
     *
     * @throws InvalidStateTransition
     * @throws EventHasStarted
     */
    public function publish(): void
    {
        if (! $this->status->canTransitionTo(EventStatus::Published)) {
            throw InvalidStateTransition::cannotPublish($this->status);
        }

        if ($this->hasStarted()) {
            throw EventHasStarted::cannotPublish();
        }

        $this->status = EventStatus::Published;
        $this->published_at = now();
    }

    /**
     * Whether the event can be cancelled now (BR-E12). The page uses it with the policy
     * to show the cancel button.
     */
    public function canBeCancelled(): bool
    {
        return $this->status->canTransitionTo(EventStatus::Cancelled) && ! $this->hasStarted();
    }

    /**
     * Cancel the event (BR-E12). It does not cancel the bookings and does not save the
     * event; the CancelEvent Action does both.
     *
     * @throws InvalidStateTransition
     * @throws EventHasStarted
     */
    public function cancel(): void
    {
        if (! $this->status->canTransitionTo(EventStatus::Cancelled)) {
            throw InvalidStateTransition::cannotCancel($this->status);
        }

        if ($this->hasStarted()) {
            throw EventHasStarted::cannotCancel();
        }

        $this->status = EventStatus::Cancelled;
        $this->cancelled_at = now();
    }

    /**
     * Whether the event needs its reminder now: published, not started, starts in the
     * next 24 hours, and no reminder was sent (BR-N4, BR-N5).
     */
    public function needsReminder(): bool
    {
        return $this->isPublished()
            && ! $this->hasStarted()
            && $this->starts_at->lessThanOrEqualTo(now()->addDay())
            && $this->reminder_sent_at === null;
    }

    /**
     * Store the send time of the reminder (BR-N5).
     */
    public function markReminderSent(): void
    {
        $this->reminder_sent_at = now();
    }

    /**
     * Whether the event can be deleted (BR-E17). The page uses it with the policy.
     */
    public function canBeDeleted(): bool
    {
        return $this->isDraft();
    }

    /**
     * Check that the event can be deleted (BR-E17). The policy refuses the other events
     * first, so this is a last line of defense.
     *
     * @throws InvalidStateTransition
     */
    public function ensureCanBeDeleted(): void
    {
        if (! $this->canBeDeleted()) {
            throw InvalidStateTransition::cannotDelete($this->status);
        }
    }
}
