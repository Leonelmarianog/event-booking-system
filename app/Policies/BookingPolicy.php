<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BookingPolicy
{
    /**
     * BR-B13. A person who cannot see the booking gets a 404, so that other bookings
     * stay unknown.
     */
    public function view(?User $user, Booking $booking): Response
    {
        if ($user !== null && ($booking->isMadeBy($user) || $booking->event->isOrganizedBy($user))) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    /**
     * BR-B9. The model checks the state of the booking and the event (BR-B10). A person
     * who cannot see the booking gets a 404, so that other bookings stay unknown.
     */
    public function cancel(User $user, Booking $booking): Response
    {
        if ($this->view($user, $booking)->denied()) {
            return Response::denyAsNotFound();
        }

        return $booking->isMadeBy($user) ? Response::allow() : Response::deny();
    }
}
