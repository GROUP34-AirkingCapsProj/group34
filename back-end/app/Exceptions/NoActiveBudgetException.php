<?php

namespace App\Exceptions;

/** No budget's period covers today for this warehouse. */
class NoActiveBudgetException extends \RuntimeException
{
    public function __construct(string $context)
    {
        parent::__construct("No active budget for {$context} covering today. Create one before approving.");
    }
}
