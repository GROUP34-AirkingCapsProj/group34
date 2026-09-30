<?php

namespace App\Services\Ledger;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reconstructs ledger rows for movements that happened before the ledger existed.
 *
 * Rows are marked origin=reconstructed and carry an honest cost_basis, because an
 * auditor has to be able to tell evidence we recorded from history we inferred.
 * Keys are the same deterministic posting_keys the live paths use, so a backfill can
 * never duplicate or overwrite a row that was posted for real.
 */
class LedgerBackfiller
{
    public const SOURCES = ['receivings', 'issuances', 'transfers', 'adjustments'];

    public function __construct(private LedgerPoster $poster)
    {
    }

    /** @return array{written:int, skipped:int, by_basis:array<string,int>} */
    public function backfillReceivings(Carbon $from, Carbon $to, int $chunk, bool $dryRun): array
    {
        $stats = ['written' => 0, 'skipped' => 0, 'by_basis' => []];

        DB::table('receiving_details as rd')
            ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
            ->leftJoin('inventory_batches as b', function ($j) {
                $j->on('b.receiving_id', '=', 'r.receiving_id')
                  ->on('b.product_id', '=', 'rd.product_id')
                  ->on('b.location_id', '=', 'r.location_id');
            })
            ->whereBetween('r.receiving_date', [$from->toDateString(), $to->toDateString()])
            ->select([
                'rd.receiving_detail_id', 'rd.receiving_id', 'rd.product_id', 'rd.quantity_amount',
                'rd.prod_price', 'rd.scanned_barcode', 'r.location_id', 'r.receiving_date',
                'r.received_by', 'b.batch_id',
            ])
            ->orderBy('rd.receiving_detail_id')
            ->chunk($chunk, function ($lines) use (&$stats, $dryRun) {
                foreach ($lines as $line) {
                    $qty = (int) $line->quantity_amount;
                    if ($qty <= 0) {
                        $stats['skipped']++;
                        continue;
                    }

                    $price = $line->prod_price !== null ? (float) $line->prod_price : 0.0;
                    $basis = $price > 0 ? 'actual' : 'unavailable';
                    $cost  = $price > 0 ? round($price, 2) : null;

                    $stats['by_basis'][$basis] = ($stats['by_basis'][$basis] ?? 0) + 1;

                    if ($dryRun) {
                        $stats['written']++;
                        continue;
                    }

                    $this->poster->post([
                        'occurred_at'    => $line->receiving_date ?: Carbon::now(),
                        'direction'      => 'in',
                        'movement_type'  => 'receiving',
                        'product_id'     => (int) $line->product_id,
                        'batch_id'       => $line->batch_id ? (int) $line->batch_id : null,
                        'serial_number'  => $line->scanned_barcode ?: null,
                        'quantity'       => $qty,
                        'unit_cost'      => $cost,
                        'cost_basis'     => $basis,
                        'to_location_id' => (int) $line->location_id,
                        'source_type'    => 'receivings',
                        'source_id'      => (int) $line->receiving_id,
                        'source_line_id' => (int) $line->receiving_detail_id,
                        'correlation_id' => 'receivings:' . $line->receiving_id,
                        'actor_user_id'  => $line->received_by ? (int) $line->received_by : null,
                        'origin'         => 'reconstructed',
                        'posting_key'    => 'receiving:' . $line->receiving_id
                                            . ':line:' . $line->receiving_detail_id . ':in',
                    ]);

                    $stats['written']++;
                }
            });

        return $stats;
    }

    /**
     * Issuance history is reconstructed from inventory_batch_movements, the only
     * record of which batch each issued piece came from. Costs come from the batch;
     * a batch with no unit_cost yields an unvalued row rather than a guess.
     *
     * @return array{written:int, skipped:int, by_basis:array<string,int>}
     */
    public function backfillIssuances(Carbon $from, Carbon $to, int $chunk, bool $dryRun): array
    {
        return $this->backfillFromMovements($from, $to, $chunk, $dryRun, 'issuances');
    }

    /** @return array{written:int, skipped:int, by_basis:array<string,int>} */
    public function backfillTransfers(Carbon $from, Carbon $to, int $chunk, bool $dryRun): array
    {
        return $this->backfillFromMovements($from, $to, $chunk, $dryRun, 'transfers');
    }

    /**
     * Shared reconstruction for the two sources whose history lives in
     * inventory_batch_movements.
     *
     * Transfers yield only their out leg: the movement table records the dispatch
     * alone, so a reconstructed transfer with no in leg is either still in transit
     * or a receipt that predates the ledger. The drift check is where that gap is
     * tracked rather than papered over with an invented arrival.
     *
     * @return array{written:int, skipped:int, by_basis:array<string,int>}
     */
    private function backfillFromMovements(Carbon $from, Carbon $to, int $chunk, bool $dryRun, string $source): array
    {
        $stats = ['written' => 0, 'skipped' => 0, 'by_basis' => []];
        $isIssuance = $source === 'issuances';

        DB::table('inventory_batch_movements as m')
            ->leftJoin('inventory_batches as b', 'b.batch_id', '=', 'm.batch_id')
            ->whereNotNull($isIssuance ? 'm.issuance_id' : 'm.transfer_id')
            ->whereBetween('m.moved_at', [$from, $to])
            ->select([
                'm.batch_movement_id', 'm.issuance_id', 'm.transfer_id', 'm.product_id', 'm.quantity',
                'm.serial_number', 'm.from_location_id', 'm.to_location_id', 'm.performed_by',
                'm.moved_at', 'm.batch_id', 'b.unit_cost', 'b.cost_basis',
            ])
            ->orderBy('m.batch_movement_id')
            ->chunk($chunk, function ($moves) use (&$stats, $dryRun, $isIssuance, $source) {
                foreach ($moves as $mv) {
                    $qty = (int) $mv->quantity;
                    if ($qty <= 0 || ! $mv->from_location_id) {
                        $stats['skipped']++;
                        continue;
                    }

                    $cost  = $mv->unit_cost !== null ? (float) $mv->unit_cost : null;
                    $basis = $cost !== null ? ($mv->cost_basis ?: 'actual') : 'unavailable';
                    $stats['by_basis'][$basis] = ($stats['by_basis'][$basis] ?? 0) + 1;

                    if ($dryRun) {
                        $stats['written']++;
                        continue;
                    }

                    $sourceId = (int) ($isIssuance ? $mv->issuance_id : $mv->transfer_id);

                    $this->poster->post([
                        'occurred_at'      => $mv->moved_at,
                        'direction'        => 'out',
                        'movement_type'    => $isIssuance ? 'issuance' : 'transfer_out',
                        'product_id'       => (int) $mv->product_id,
                        'batch_id'         => (int) $mv->batch_id,
                        'serial_number'    => $mv->serial_number ?: null,
                        'quantity'         => $qty,
                        'unit_cost'        => $cost,
                        'cost_basis'       => $basis,
                        'from_location_id' => (int) $mv->from_location_id,
                        'to_location_id'   => $mv->to_location_id ? (int) $mv->to_location_id : null,
                        'source_type'      => $source,
                        'source_id'        => $sourceId,
                        'correlation_id'   => $source . ':' . $sourceId,
                        'actor_user_id'    => $mv->performed_by ? (int) $mv->performed_by : null,
                        'origin'           => 'reconstructed',
                        // Keyed on the movement row, the only stable identity these
                        // historic slices have.
                        'posting_key'      => ($isIssuance ? 'issuance:' : 'transfer:') . $sourceId
                                              . ':movement:' . $mv->batch_movement_id . ':out',
                    ]);

                    $stats['written']++;
                }
            });

        return $stats;
    }

    /** @return array{written:int, skipped:int, by_basis:array<string,int>} */
    public function backfillAdjustments(Carbon $from, Carbon $to, int $chunk, bool $dryRun): array
    {
        $stats = ['written' => 0, 'skipped' => 0, 'by_basis' => []];

        DB::table('adjustment_details as ad')
            ->join('adjustments as a', 'a.adjustment_id', '=', 'ad.adjustment_id')
            ->whereBetween('a.adjustment_date', [$from->toDateString(), $to->toDateString()])
            ->select([
                'ad.adjustment_detail_id', 'ad.adjustment_id', 'ad.product_id',
                'ad.add_quantity', 'ad.deduct_quantity',
                'a.location_id', 'a.adjustment_date', 'a.adjustment_type', 'a.created_by',
            ])
            ->orderBy('ad.adjustment_detail_id')
            ->chunk($chunk, function ($lines) use (&$stats, $dryRun) {
                foreach ($lines as $line) {
                    $net = (int) $line->add_quantity - (int) $line->deduct_quantity;
                    if ($net === 0) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Historic adjustments carry no cost of their own. Rather than
                    // inventing one, value is left unavailable: the quantity is the
                    // fact, and a fabricated peso figure is worse than none.
                    $stats['by_basis']['unavailable'] = ($stats['by_basis']['unavailable'] ?? 0) + 1;

                    if ($dryRun) {
                        $stats['written']++;
                        continue;
                    }

                    $this->poster->post([
                        'occurred_at'      => $line->adjustment_date,
                        'direction'        => $net > 0 ? 'in' : 'out',
                        'movement_type'    => 'adjustment',
                        'product_id'       => (int) $line->product_id,
                        'quantity'         => abs($net),
                        'unit_cost'        => null,
                        'cost_basis'       => 'unavailable',
                        'from_location_id' => $net < 0 ? (int) $line->location_id : null,
                        'to_location_id'   => $net > 0 ? (int) $line->location_id : null,
                        'source_type'      => 'adjustments',
                        'source_id'        => (int) $line->adjustment_id,
                        'source_line_id'   => (int) $line->adjustment_detail_id,
                        'correlation_id'   => 'adjustments:' . $line->adjustment_id,
                        'actor_user_id'    => $line->created_by ? (int) $line->created_by : null,
                        'notes'            => $line->adjustment_type,
                        'origin'           => 'reconstructed',
                        'posting_key'      => 'adjustment:' . $line->adjustment_id
                                              . ':line:' . $line->adjustment_detail_id
                                              . ':' . ($net > 0 ? 'in' : 'out'),
                    ]);

                    $stats['written']++;
                }
            });

        return $stats;
    }
}
