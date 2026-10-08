<?php

namespace App\Exceptions\Domain;

/**
 * BR-U5: the user deleted the account, so the user cannot act any more.
 */
class AccountDeleted extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('Your account was deleted.'));
    }
}
