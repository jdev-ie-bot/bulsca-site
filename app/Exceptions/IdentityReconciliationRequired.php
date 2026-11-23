<?php

namespace App\Exceptions;

use RuntimeException;

class IdentityReconciliationRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Your account needs to be reconciled. Please contact a portal administrator.');
    }
}
