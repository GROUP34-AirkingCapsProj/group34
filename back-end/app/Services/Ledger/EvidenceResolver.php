<?php

namespace App\Services\Ledger;

use App\Models\Adjustment;
use App\Models\InventoryBatch;
use App\Models\InventoryLedgerEntry;
use App\Models\Issuance;
use App\Models\Receiving;
use App\Models\Transfer;
use App\Services\History\RecordHistoryService;

/**
 * Turns a ledger row into the evidence behind it: the other legs of the same event,
 * the source document, and the change history already recorded against that document
 * in audit_trail.
 *
 * The history comes from RecordHistoryService rather than a second implementation,
 * so the Explorer and the existing record timelines can never tell different stories
 * about what happened to the same record.
 */
class EvidenceResolver
{
    /** source_type -> the model whose key source_id points at. */
    private const SOURCE_MODELS = [
        'receivings'  => Receiving::class,
        'issuances'   => Issuance::class,
        'transfers'   => Transfer::class,
        'adjustments' => Adjustment::class,
    ];

    public function __construct(
        private LedgerQueryService $ledger,
        private RecordHistoryService $history
    ) {
    }

    /** @return array<string,mixed> */
    public function forCorrelation(string $correlationId): array
    {
        $rows = InventoryLedgerEntry::where('correlation_id', $correlationId)
            ->with([
                'product:product_id,product_name,product_code',
                'actor:user_id,first_name,last_name,username',
            ])
            ->orderBy('occurred_at')
            ->orderBy('ledger_id')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'correlation_id' => $correlationId,
                'legs'           => [],
                'totals'         => ['quantity_in' => 0, 'quantity_out' => 0, 'value_in' => 0.0, 'value_out' => 0.0],
                'source'         => null,
                'history'        => [],
                'batches'        => [],
            ];
        }

        $first = $rows->first();

        return [
            'correlation_id' => $correlationId,
            'legs'           => $rows->map(fn ($r) => $this->ledger->presentRow($r))->all(),
            'totals'         => [
                'quantity_in'  => (int) $rows->where('direction', 'in')->sum('quantity'),
                'quantity_out' => (int) $rows->where('direction', 'out')->sum('quantity'),
                'value_in'     => round((float) $rows->where('direction', 'in')->sum(fn ($r) => (float) $r->extended_value), 2),
                'value_out'    => round((float) $rows->where('direction', 'out')->sum(fn ($r) => (float) $r->extended_value), 2),
            ],
            'source'  => $this->sourceDocument((string) $first->source_type, (int) $first->source_id),
            'history' => $this->history->forRecord((string) $first->source_type, (int) $first->source_id),
            'batches' => $this->batchesFor($rows->pluck('batch_id')->filter()->unique()->all()),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function lifecycleForSerial(string $serialNumber): array
    {
        return $this->lifecycle(
            InventoryLedgerEntry::where('serial_number', $serialNumber)
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function lifecycleForBatch(int $batchId): array
    {
        return $this->lifecycle(
            InventoryLedgerEntry::where('batch_id', $batchId)
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function lifecycle($query): array
    {
        return $query
            ->with([
                'product:product_id,product_name,product_code',
                'actor:user_id,first_name,last_name,username',
            ])
            ->orderBy('occurred_at')
            ->orderBy('ledger_id')
            ->get()
            ->map(fn ($r) => $this->ledger->presentRow($r))
            ->all();
    }

    /** @return array<string,mixed>|null */
    private function sourceDocument(string $sourceType, int $sourceId): ?array
    {
        $class = self::SOURCE_MODELS[$sourceType] ?? null;
        if (! $class) {
            return null;
        }

        $record = $class::find($sourceId);

        if (! $record) {
            // The document was archived or removed, but the ledger row survives it.
            // That is the whole reason inventory_ledger declares no foreign keys:
            // the movement still happened, and saying so is the point.
            return ['type' => $sourceType, 'id' => $sourceId, 'missing' => true, 'attributes' => []];
        }

        return [
            'type'       => $sourceType,
            'id'         => $sourceId,
            'missing'    => false,
            'attributes' => $record->toArray(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function batchesFor(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        return InventoryBatch::whereIn('batch_id', $batchIds)
            ->get([
                'batch_id', 'product_id', 'location_id', 'receiving_id',
                'quantity_received', 'quantity_remaining', 'received_at',
                'unit_cost', 'cost_basis', 'zone_label',
            ])
            ->map(fn ($b) => $b->toArray())
            ->all();
    }
}
