<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]
#[Backoff([10, 60])]
class BookingCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The queue sends the email only after the transaction commits (BR-N6).
     */
    public function __construct(public readonly Booking $booking)
    {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $event = $this->booking->event;

        return (new MailMessage)
            ->subject("Booking cancelled: {$event->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your booking for {$event->title} is cancelled.")
            ->line("Reference: {$this->booking->reference}")
            ->line("Seats: {$this->booking->quantity}")
            ->line('Starts: '.$event->starts_at->utc()->format('D j M Y, H:i').' UTC')
            ->action('View event', route('events.show', $event));
    }
}
