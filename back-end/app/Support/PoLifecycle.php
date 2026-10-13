<?php

namespace App\Support;

/** Canonical lifecycle; legacy lookup IDs remain compatible during rollout. */
final class PoLifecycle
{
    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const PARTIALLY_RECEIVED = 'partially_received';

    public const FULLY_RECEIVED = 'fully_received';

    public const SHORT_CLOSED = 'short_closed';

    public const CANCELLED = 'cancelled';

    public const LABELS = [
        self::DRAFT => 'Draft', self::OPEN => 'Approved/Open',
        self::PARTIALLY_RECEIVED => 'Partially Received',
        self::FULLY_RECEIVED => 'Fully Received',
        self::SHORT_CLOSED => 'Short-Closed', self::CANCELLED => 'Cancelled',
    ];

    /** Quantities are integer pieces. Overfill on one line cannot fill another. */
    public static function compute(array $lines, bool $approved, bool $cancelled = false): string
    {
        $received = 0;
        $closed = 0;
        $remaining = 0;
        foreach ($lines as $line) {
            $received += $line['received'];
            $closed += $line['short_closed'];
            $remaining += max(0, $line['ordered'] - $line['received'] - $line['short_closed']);
        }

        if ($cancelled && $received === 0) {
            return self::CANCELLED;
        }
        if ($lines !== [] && $remaining === 0) {
            return $closed > 0 ? self::SHORT_CLOSED : self::FULLY_RECEIVED;
        }
        if ($received > 0) {
            return self::PARTIALLY_RECEIVED;
        }

        return $approved ? self::OPEN : self::DRAFT;
    }
}
