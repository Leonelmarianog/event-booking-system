<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /**
     * Whether a booking with this status can change to the given status (BR-B10).
     */
    public function canTransitionTo(self $status): bool
    {
        return $this === self::Confirmed && $status === self::Cancelled;
    }
}
