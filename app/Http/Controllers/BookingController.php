<?php

namespace App\Http\Controllers;

use App\Actions\ReserveSeats\ReserveSeats;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BookingController extends Controller
{
    /**
     * Book seats of the event and show the event page.
     */
    public function store(StoreBookingRequest $request, Event $event, ReserveSeats $reserveSeats): RedirectResponse
    {
        $booking = $reserveSeats->handle($event, $request->user(), $request->integer('quantity'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{1} You booked 1 seat. Reference: :reference.|[2,*] You booked :count seats. Reference: :reference.',
                $booking->quantity,
                ['reference' => $booking->reference],
            ),
        ]);

        return to_route('events.show', $event);
    }
}
