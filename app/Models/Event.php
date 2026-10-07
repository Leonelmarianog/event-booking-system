<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Exceptions\Domain\CapacityBelowBookedSeats;
use App\Exceptions\Domain\EventHasStarted;
use App\Exceptions\Domain\InvalidStateTransition;
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
