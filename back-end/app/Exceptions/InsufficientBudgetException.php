<?php

namespace App\Exceptions;

class InsufficientBudgetException extends \RuntimeException
{
    public function __construct(string $periodLabel, float $requested, float $available)
    {
        parent::__construct(sprintf(
            'Estimated total ₱%s exceeds available budget ₱%s for %s',
            number_format($requested, 2),
            number_format(max($available, 0), 2),
            $periodLabel
        ));
    }
}
