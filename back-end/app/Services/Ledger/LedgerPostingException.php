<?php

namespace App\Services\Ledger;

use RuntimeException;

/**
 * A posting contract violation. Thrown, never swallowed: LedgerPoster runs inside
 * the caller's transaction, so this rolls the business operation back. That is
 * deliberate and the opposite of the Auditable trait, which reports and continues
 * so that logging can never break a save. An unrecorded movement is worse than a
 * failed one.
 */
class LedgerPostingException extends RuntimeException
{
}
