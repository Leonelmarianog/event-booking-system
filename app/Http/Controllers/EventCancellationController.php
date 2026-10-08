<?php

namespace App\Http\Controllers;

use App\Actions\CancelEvent\CancelEvent;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class EventCancellationController extends Controller
{
    /**
     * Cancel the event and its bookings, and show the event page.
     */
    public function store(Event $event, CancelEvent $cancelEvent): RedirectResponse
    {
        $cancelledBookings = $cancelEvent->handle($event);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{0} Event cancelled.|{1} Event cancelled. 1 booking was cancelled.|[2,*] Event cancelled. :count bookings were cancelled.',
                $cancelledBookings,
            ),
        ]);

        return to_route('events.show', $event);
    }
}
