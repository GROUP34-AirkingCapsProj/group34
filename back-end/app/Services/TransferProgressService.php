<?php

namespace App\Services;

use App\Models\Transfer;
use Illuminate\Support\Facades\DB;

class TransferProgressService
{
    public function summary(Transfer $transfer): array
    {
        $root = $transfer->continuation_of_transfer_id ? Transfer::findOrFail($transfer->continuation_of_transfer_id) : $transfer;
        $requests = $root->requested_products ?? $root->details->map(fn ($d) => [
            'product_id' => $d->product_id, 'quantity_requested' => $d->quantity_requested,
        ])->all();
        $moves = Transfer::with('details')->where(function ($q) use ($root) {
            $q->where('transfer_id', $root->transfer_id)->orWhere('continuation_of_transfer_id', $root->transfer_id);
        })->get();
        $requested = collect($requests)->groupBy('product_id')->map(fn ($rows) => $rows->sum('quantity_requested'));
        $names = \App\Models\Product::whereIn('product_id', $requested->keys())->pluck('product_name', 'product_id');
        $details = $moves->flatMap(fn ($t) => $t->details);
        $lines = [];
        foreach ($requested as $pid => $quantity) {
            $matching = $details->where('product_id', $pid);
            $received = (int) $matching->sum('quantity_receipt_posted');
            $shipped = (int) $matching->sum('quantity_transferred');
            $lines[] = [
                'product_id' => (int) $pid, 'quantity_requested' => (int) $quantity,
                'product_name' => $names[$pid] ?? "Product #{$pid}",
                'quantity_received' => $received, 'quantity_shipped' => $shipped,
                'remaining_to_receive' => max(0, $shipped - $received),
                'remaining_to_send' => max(0, (int) $quantity - $shipped),
                'remaining' => max(0, (int) $quantity - $received),
            ];
        }
        return ['request_id' => (int) $root->transfer_id, 'lines' => $lines,
            'complete' => collect($lines)->every(fn ($line) => $line['remaining'] === 0),
            'continuations' => $moves->where('transfer_id', '!=', $root->transfer_id)->map(fn ($t) => [
                'transfer_id' => $t->transfer_id, 'transfer_number' => $t->transfer_number,
                'status_name' => $t->status?->status_name,
            ])->values()->all()];
    }

    public function updateRoot(Transfer $transfer): void
    {
        $root = $transfer->continuation_of_transfer_id ? Transfer::findOrFail($transfer->continuation_of_transfer_id) : $transfer;
        $summary = $this->summary($root);
        $root->status_id = (int) DB::table('status_lookup')->where('status_category', 'transfer')
            ->where('status_name', $summary['complete'] ? 'Received' : 'Partial')->value('status_id');
        $root->save();
    }
}
