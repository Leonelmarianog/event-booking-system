<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';

    /**
     * Whether an event with this status can change to the given status. See the state
     * diagram in docs/design/business-rules.md.
     */
    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Draft => in_array($status, [self::Published, self::Cancelled], true),
            self::Published => $status === self::Cancelled,
            self::Cancelled => false,
        };
    }
}
