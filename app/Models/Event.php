<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Exceptions\Domain\CapacityBelowBookedSeats;
use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * Whether the given user is the organizer of the event.
     */
    public function isOrganizedBy(User $user): bool
    {
        return $this->organizer_id === $user->id;
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
}
