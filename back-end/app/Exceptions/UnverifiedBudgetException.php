<?php

namespace App\Exceptions;

class UnverifiedBudgetException extends \RuntimeException
{
    public function __construct(string $periodLabel, string $status)
    {
        $reason = $status === 'rejected'
            ? "was rejected by the auditor"
            : "has not been verified by an auditor yet";
        parent::__construct("Budget {$periodLabel} {$reason}.");
    }
}
