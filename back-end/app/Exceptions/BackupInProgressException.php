<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a backup or restore is requested while another one is still running. */
class BackupInProgressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A backup or restore is already in progress. Try again shortly.');
    }
}
