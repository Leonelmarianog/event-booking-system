<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Exceptions\Domain\EventHasStarted;
use App\Exceptions\Domain\InvalidStateTransition;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $reference
 * @property int $event_id
 * @property int $user_id
 * @property int $quantity
 * @property BookingStatus $status
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Event $event
 * @property-read User $attendee
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory, HasUlids;

    /**
     * The columns that get a new ULID on create. The primary key stays an integer.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['reference'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'user_id' => 'integer',
            'quantity' => 'integer',
            'status' => BookingStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * The event of the booking.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The user who made the booking.
     *
     * @return BelongsTo<User, $this>
     */
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Whether the given user made the booking.
     */
    public function isMadeBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Whether the booking is confirmed.
     */
    public function isConfirmed(): bool
    {
        return $this->status === BookingStatus::Confirmed;
    }

    /**
     * Whether the booking is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === BookingStatus::Cancelled;
    }

    /**
     * Whether the booking can be cancelled now (BR-B10). The pages use it to show the
     * cancel button.
     */
    public function canBeCancelled(): bool
    {
        return $this->status->canTransitionTo(BookingStatus::Cancelled) && ! $this->event->hasStarted();
    }

    /**
     * Cancel the booking and give its seats back to the event (BR-B10, BR-B11). It saves
     * neither the booking nor the event.
     *
     * @throws InvalidStateTransition
     * @throws EventHasStarted
     */
    public function cancel(): void
    {
        if (! $this->status->canTransitionTo(BookingStatus::Cancelled)) {
            throw InvalidStateTransition::cannotCancelBooking($this->status);
        }

        if ($this->event->hasStarted()) {
            throw EventHasStarted::cannotCancelBooking();
        }

        $this->status = BookingStatus::Cancelled;
        $this->cancelled_at = now();
        $this->event->releaseSeats($this->quantity);
    }
}
