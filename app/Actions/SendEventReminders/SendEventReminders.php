<?php

namespace App\Actions\SendEventReminders;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Notifications\EventReminder;
use Illuminate\Support\Facades\DB;

class SendEventReminders
{
    /**
     * Send the reminders for all published events that start in the next 24 hours and
     * have no reminder yet (BR-N4, BR-N5). Each event has its own transaction. An error
     * on one event is reported, and the run goes on with the next event. It returns the
     * number of handled events.
     */
    public function handle(): int
    {
        return Event::query()
            ->dueForReminder()
            ->orderBy('starts_at')
            ->pluck('id')
            ->filter(fn (int $eventId): bool => rescue(fn (): bool => $this->remindEvent($eventId), false))
            ->count();
    }

    /**
     * Lock the event row, check again that it needs the reminder, mark the reminder as
     * sent, and send `EventReminder` to the attendee of each confirmed booking after the
     * commit (BR-N5, BR-N6). The other write Actions lock the event row first, so the
     * confirmed bookings cannot change while this lock is held. It returns false when
     * the event changed after the query, for example when another run or a cancel came
     * first.
     */
    public function remindEvent(int $eventId): bool
    {
        return DB::transaction(function () use ($eventId): bool {
            $event = Event::query()->lockForUpdate()->find($eventId);

            if ($event === null || ! $event->needsReminder()) {
                return false;
            }

            $event->markReminderSent();
            $event->save();

            $event->bookings()
                ->where('status', BookingStatus::Confirmed)
                ->with('attendee')
                ->get()
                ->each(function (Booking $booking) use ($event): void {
                    $booking->setRelation('event', $event);
                    $booking->attendee->notify(new EventReminder($booking));
                });

            return true;
        });
    }
}
