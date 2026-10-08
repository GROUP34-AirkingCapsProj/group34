<?php

namespace App\Services\Ledger;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The Explorer's scope, validated once so every query shares one interpretation of
 * it.
 *
 * The window is capped here rather than truncated in a query: an auditor who asks
 * for too wide a range is told so, instead of being handed a partial answer that
 * looks complete. A silently truncated total is exactly the kind of figure this
 * whole feature exists to stop producing.
 */
class LedgerFilter
{
    public const MAX_DAYS = 366;

    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $locationId = null,
        public readonly ?int $productId = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $actorUserId = null,
        public readonly string $lens = 'inventory',
        public readonly ?string $movementType = null,
    ) {
    }

    public static function fromArray(array $q): self
    {
        $from = Carbon::parse($q['from'] ?? Carbon::now()->startOfMonth()->toDateString())->startOfDay();
        $to   = Carbon::parse($q['to'] ?? Carbon::now()->toDateString())->endOfDay();

        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('The end of the period is before its start.');
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new InvalidArgumentException(
                'That period is wider than the ' . self::MAX_DAYS . ' days this view can total at once. '
                . 'Narrow the range, or export the movement ledger instead.'
            );
        }

        $int = fn ($v) => ($v === null || $v === '') ? null : (int) $v;

        return new self(
            from: $from,
            to: $to,
            locationId: $int($q['location_id'] ?? null),
            productId: $int($q['product_id'] ?? null),
            categoryId: $int($q['category_id'] ?? null),
            actorUserId: $int($q['user_id'] ?? null),
            lens: (($q['lens'] ?? 'inventory') === 'finance') ? 'finance' : 'inventory',
            movementType: ($q['movement_type'] ?? null) ?: null,
        );
    }
}
