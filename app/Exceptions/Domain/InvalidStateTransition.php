<?php

namespace App\Exceptions\Domain;

use App\Enums\EventStatus;

/**
 * The status of the event does not allow the change (see EventStatus::canTransitionTo()).
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
     * BR-E17: only a draft event can be deleted.
     */
    public static function cannotDelete(EventStatus $status): self
    {
        return new self(__('Only a draft event can be deleted. This event is :status.', [
            'status' => $status->value,
        ]));
    }
}
