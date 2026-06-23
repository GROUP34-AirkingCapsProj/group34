<?php

namespace App\Support;

use App\Events\ApprovalQueueChanged;
use App\Events\InventoryChanged;
use App\Events\PurchaseOrderChanged;
use App\Events\TransferChanged;
use Illuminate\Support\Facades\Log;

/**
 * One-line, failure-safe entry point for realtime signal broadcasts.
 * Null location ids are no-ops (e.g. a record without a resolvable location).
 * Any broadcast/transport error is logged and swallowed so it never breaks the
 * action that triggered it.
 */
class RealtimeBroadcaster
{
    public static function approvalQueueChanged(?int $locationId, string $type = 'changed'): void
    {
        self::safe(fn () => $locationId !== null
            ? broadcast(new ApprovalQueueChanged($locationId, $type))
            : null);
    }

    public static function inventoryChanged(?int $locationId, string $type = 'changed'): void
    {
        self::safe(fn () => $locationId !== null
            ? broadcast(new InventoryChanged($locationId, $type))
            : null);
    }

    public static function purchaseOrderChanged(?int $locationId, string $type = 'changed'): void
    {
        self::safe(fn () => $locationId !== null
            ? broadcast(new PurchaseOrderChanged($locationId, $type))
            : null);
    }

    public static function transferChanged(?int $from, ?int $to, string $type = 'changed'): void
    {
        self::safe(function () use ($from, $to, $type) {
            if ($from === null && $to === null) {
                return null;
            }
            $a = $from ?? $to;
            $b = $to ?? $from;
            return broadcast(new TransferChanged($a, $b, $type));
        });
    }

    private static function safe(\Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Realtime broadcast failed: ' . $e->getMessage());
        }
    }
}
