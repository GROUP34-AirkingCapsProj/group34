<?php

namespace App\Exceptions;

/**
 * No budget covers today for this warehouse. Budgets used to carry an auditor
 * verification state, which collapsed "unverified" and "absent" into one error;
 * verification is gone, so absence is the only remaining failure.
 */
class NoActiveBudgetException extends \RuntimeException
{
    public function __construct(string $context)
    {
        parent::__construct("No active budget for {$context} covering today. Create one before approving.");
    }
}
