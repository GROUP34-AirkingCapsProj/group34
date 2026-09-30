<?php

namespace App\Services\Ledger;

use App\Models\InventoryLedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * The single writer for inventory_ledger. Callers invoke this from inside the DB
 * transaction they already hold, so a posting failure rolls their work back too.
 */
class LedgerPoster
{
    /** Movement types that describe money rather than goods. */
    private const FINANCE_TYPES = ['budget_allocation', 'encumbrance'];

    public function post(array $attrs): InventoryLedgerEntry
    {
        $attrs = $this->normalize($attrs);
        $this->validate($attrs);

        $existing = InventoryLedgerEntry::where('posting_key', $attrs['posting_key'])->first();
        if ($existing) {
            return $existing;
        }

        try {
            $entry = InventoryLedgerEntry::create($attrs);

            // Re-read so the caller sees exactly what the row now holds: the decimal
            // columns come back from the database as strings rounded to its scale,
            // not the in-memory floats that were handed to create().
            return $entry->refresh();
        } catch (QueryException $e) {
            // Lost a race on the unique index: the other writer's row is the row.
            $row = InventoryLedgerEntry::where('posting_key', $attrs['posting_key'])->first();
            if ($row) {
                return $row;
            }
            throw $e;
        }
    }

    private function normalize(array $a): array
    {
        $a['direction']   = $a['direction'] ?? 'out';
        $a['cost_basis']  = $a['cost_basis'] ?? 'actual';
        $a['origin']      = $a['origin'] ?? 'posted';
        $a['occurred_at'] = $a['occurred_at'] ?? Carbon::now();
        $a['posted_at']   = $a['posted_at'] ?? Carbon::now();
        $a['quantity']    = (int) ($a['quantity'] ?? 0);

        $a['lens'] = $a['lens']
            ?? (in_array($a['movement_type'] ?? '', self::FINANCE_TYPES, true) ? 'finance' : 'inventory');

        if (! array_key_exists('correlation_id', $a) || $a['correlation_id'] === null) {
            $a['correlation_id'] = ($a['source_type'] ?? 'unknown') . ':' . ($a['source_id'] ?? '0');
        }

        // Value is derived only when the caller did not freeze one itself — a
        // reversal passes the original's value through and must not be recomputed
        // from a cost that may have moved since.
        if (! array_key_exists('extended_value', $a) || $a['extended_value'] === null) {
            $a['extended_value'] = ($a['cost_basis'] === 'unavailable' || ($a['unit_cost'] ?? null) === null)
                ? null
                : round((float) $a['unit_cost'] * $a['quantity'], 2);
        }

        return $a;
    }

    private function validate(array $a): void
    {
        if (empty($a['posting_key'])) {
            $this->fail('posting_key is required: without it a retry would double the value.', $a);
        }
        if (! in_array($a['direction'], InventoryLedgerEntry::DIRECTIONS, true)) {
            $this->fail("direction '{$a['direction']}' is not one of in|out|neutral.", $a);
        }
        if (! in_array($a['cost_basis'], InventoryLedgerEntry::COST_BASES, true)) {
            $this->fail("cost_basis '{$a['cost_basis']}' is not a recognised basis.", $a);
        }
        if (empty($a['movement_type'])) {
            $this->fail('movement_type is required.', $a);
        }
        if (empty($a['source_type']) || empty($a['source_id'])) {
            $this->fail('source_type and source_id are required: a row with no evidence pointer is not evidence.', $a);
        }

        if ($a['direction'] === 'neutral') {
            return;
        }

        if (empty($a['product_id'])) {
            $this->fail('product_id is required for a stock movement.', $a);
        }
        if ($a['quantity'] <= 0) {
            $this->fail('quantity must be greater than zero; direction carries the sign.', $a);
        }
        if ($a['direction'] === 'out' && empty($a['from_location_id'])) {
            $this->fail('from_location_id is required for an out movement.', $a);
        }
        if ($a['direction'] === 'in' && empty($a['to_location_id'])) {
            $this->fail('to_location_id is required for an in movement.', $a);
        }
    }

    private function fail(string $message, array $context): void
    {
        throw new LedgerPostingException(
            'Ledger posting refused: ' . $message . ' Context: ' . json_encode([
                'movement_type' => $context['movement_type'] ?? null,
                'posting_key'   => $context['posting_key'] ?? null,
                'source'        => ($context['source_type'] ?? null) . '#' . ($context['source_id'] ?? null),
            ])
        );
    }
}
