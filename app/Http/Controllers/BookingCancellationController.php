<?php

namespace App\Http\Controllers;

use App\Actions\CancelBooking\CancelBooking;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BookingCancellationController extends Controller
{
    /**
     * Cancel the booking and go back to the page of the request.
     */
    public function store(Booking $booking, CancelBooking $cancelBooking): RedirectResponse
    {
        $booking = $cancelBooking->handle($booking);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('You cancelled your booking. Reference: :reference.', ['reference' => $booking->reference]),
        ]);

        return back(fallback: route('bookings.index'));
    }
}
