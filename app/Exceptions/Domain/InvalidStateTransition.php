<?php

namespace App\Exceptions\Domain;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;

/**
 * The status does not allow the change (see EventStatus::canTransitionTo() and
 * BookingStatus::canTransitionTo()).
 */
class InvalidStateTransition extends DomainException
{
    /**
     * BR-E10: only a draft event can be published.
     */
    public static function cannotPublish(EventStatus $status): self
    {
        return new self(__('Only a draft event can be published. This event is :status.', [
            'status' => $status->value,
        ]));
    }

    /**
     * BR-E12: only a draft or published event can be cancelled.
     */
    public static function cannotCancel(EventStatus $status): self
    {
        return new self(__('Only a draft or published event can be cancelled. This event is :status.', [
            'status' => $status->value,
        ]));
    }

    /**
     * BR-E17: only a draft event can be deleted.
     */
    public static function cannotDelete(EventStatus $status): self
    {
        return new self(__('Only a draft event can be deleted. This event is :status.', [
            'status' => $status->value,
        ]));
    }

    /**
     * BR-B10: only a confirmed booking can be cancelled.
     */
    public static function cannotCancelBooking(BookingStatus $status): self
    {
        return new self(__('Only a confirmed booking can be cancelled. This booking is :status.', [
            'status' => $status->value,
        ]));
    }
}
