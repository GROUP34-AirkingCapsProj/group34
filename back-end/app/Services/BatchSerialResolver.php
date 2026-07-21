<?php

namespace App\Services;

use App\Models\ItemSerial;
use Illuminate\Support\Facades\DB;

class BatchSerialResolver
{
    /**
     * Map each batch to the serial numbers (appliances) or scanned barcodes
     * (consumables) belonging to it, keyed by batch_id.
     *
     * @param  \Illuminate\Support\Collection $batches items exposing
     *         batch_id, product_id, location_id, receiving_id
     * @return array<int|string, string[]>
     */
    public function resolve($batches): array
    {
        $withReceiving = $batches->filter(fn ($b) => $b->receiving_id !== null);
        if ($withReceiving->isEmpty()) {
            return [];
        }

        $receivingIds = $withReceiving->pluck('receiving_id')->unique()->values()->all();
        $productIds   = $withReceiving->pluck('product_id')->unique()->values()->all();
        $locationIds  = $withReceiving->pluck('location_id')->unique()->values()->all();

        // Appliance serials currently in the batch (serial_type = 'received').
        $applianceMap = [];
        ItemSerial::query()
            ->whereIn('receiving_id', $receivingIds)
            ->whereIn('product_id', $productIds)
            ->whereIn('location_id', $locationIds)
            ->where('serial_type', 'received')
            ->get(['serial_number', 'product_id', 'location_id', 'receiving_id'])
            ->each(function ($s) use (&$applianceMap) {
                $key = $s->product_id . '|' . $s->location_id . '|' . $s->receiving_id;
                $applianceMap[$key][] = $s->serial_number;
            });

        // Consumable scanned barcodes.
        $consumableMap = [];
        DB::table('receiving_details')
            ->join('receivings', 'receivings.receiving_id', '=', 'receiving_details.receiving_id')
            ->whereIn('receiving_details.receiving_id', $receivingIds)
            ->whereIn('receiving_details.product_id', $productIds)
            ->whereIn('receivings.location_id', $locationIds)
            ->whereNotNull('receiving_details.scanned_barcode')
            ->get([
                'receiving_details.scanned_barcode as scanned_barcode',
                'receiving_details.product_id as product_id',
                'receivings.location_id as location_id',
                'receiving_details.receiving_id as receiving_id',
            ])
            ->each(function ($r) use (&$consumableMap) {
                $key = $r->product_id . '|' . $r->location_id . '|' . $r->receiving_id;
                $consumableMap[$key][] = $r->scanned_barcode;
            });

        $result = [];
        foreach ($batches as $batch) {
            if ($batch->receiving_id === null) {
                $result[$batch->batch_id] = [];
                continue;
            }
            $key = $batch->product_id . '|' . $batch->location_id . '|' . $batch->receiving_id;
            $result[$batch->batch_id] = $applianceMap[$key] ?? $consumableMap[$key] ?? [];
        }

        return $result;
    }
}
