<?php

namespace App\Exceptions\Domain;

use RuntimeException;

/**
 * A business rule does not allow the action. The message is for the user.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * The form field that the error belongs to, if any.
     */
    public function field(): ?string
    {
        return null;
    }
}
