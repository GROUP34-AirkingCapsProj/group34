<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\Transfer;
use App\Models\User;
use App\Models\AuditTrail;
use App\Support\PermissionResolver;

/** Live work notifications, shared by the bell and Alerts & Notices. */
class WarehouseNotificationService
{
    public function forUser(User $user): array
    {
        $scoped = ! $user->isCentralized();
        $locationId = $user->scopedLocationId();
        if ($scoped && $locationId === null) {
            return [];
        }
        $resolver = app(PermissionResolver::class);
        $can = fn ($feature) => $resolver->canUser((int) $user->user_id, $feature, 'read');
        $notifications = [];

        if ($can('delivery_receiving')) {
            $pos = PurchaseOrder::with(['supplier', 'location', 'status'])
                ->whereHas('status', fn ($q) => $q->where('status_category', 'purchase_order')
                    ->whereIn('status_name', ['Approved', 'Authorized', 'Partially Received']))
                ->when($scoped, fn ($q) => $q->where('location_id', $locationId))
                ->get();
            $statusTimes = $this->statusTimes('purchase_orders', $pos->pluck('po_id')->all());
            foreach ($pos as $po) {
                $notifications[] = $this->notification($user, 'po_receive:' . $po->po_id, 'po_receive',
                    $po->po_number . ' approved for receiving',
                    ($po->supplier?->supplier_name ?? 'Supplier') . ' → ' . ($po->location?->location_name ?? 'Warehouse')
                        . ($po->expected_delivery_date ? '. Expected delivery: ' . $po->expected_delivery_date->toDateString() : ''),
                    '/admin/inventory-operation?mode=receive-po', $statusTimes[$po->po_id] ?? $po->updated_at ?? $po->created_at);
            }
        }

        if ($can('stock_transfers') && $can('inventory_transfers')) {
            $transfers = Transfer::with(['status', 'fromLocation', 'toLocation'])
                ->whereHas('status', fn ($q) => $q->where('status_category', 'transfer')
                    ->whereIn('status_name', ['Approved', 'In Transit']))
                ->when($scoped, fn ($q) => $q->where(function ($q) use ($locationId) {
                    $q->where(function ($q) use ($locationId) {
                        $q->where('from_location_id', $locationId)
                            ->whereHas('status', fn ($s) => $s->where('status_name', 'Approved'));
                    })->orWhere(function ($q) use ($locationId) {
                        $q->where('to_location_id', $locationId)
                            ->whereHas('status', fn ($s) => $s->where('status_name', 'In Transit'));
                    });
                }))->get();
            $statusTimes = $this->statusTimes('transfers', $transfers->pluck('transfer_id')->all());
            foreach ($transfers as $transfer) {
                $dispatch = $transfer->status->status_name === 'Approved';
                $type = $dispatch ? 'transfer_dispatch' : 'transfer_receive';
                $notifications[] = $this->notification($user, $type . ':' . $transfer->transfer_id, $type,
                    $transfer->transfer_number . ($dispatch ? ' ready to dispatch' : ' incoming — ready to receive'),
                    ($transfer->fromLocation?->location_name ?? 'Source') . ' → ' . ($transfer->toLocation?->location_name ?? 'Destination'),
                    '/admin/transfers?tab=' . ($dispatch ? 'send' : 'receive') . '&focus=transfer:' . $transfer->transfer_id,
                    $statusTimes[$transfer->transfer_id] ?? $transfer->updated_at ?? $transfer->created_at);
            }
        }
        usort($notifications, fn ($a, $b) => strcmp($b['occurred_at'] ?? '', $a['occurred_at'] ?? ''));
        return $notifications;
    }

    /** Scans and note edits must not turn an already-read approval unread again. */
    private function statusTimes(string $table, array $ids): array
    {
        if ($ids === []) return [];
        $times = [];
        $events = AuditTrail::where('table_name', $table)->whereIn('record_id', $ids)
            ->whereNotNull('new_values->status_id')
            ->whereIn('action', ['create', 'update'])->orderByDesc('audit_id')
            ->get(['record_id', 'action', 'new_values', 'old_values', 'created_at']);
        foreach ($events as $event) {
            if (isset($times[$event->record_id])) continue;
            $next = $event->new_values['status_id'] ?? null;
            $previous = $event->old_values['status_id'] ?? null;
            if ($next !== null && ($event->action === 'create' || (string) $next !== (string) $previous)) {
                $times[$event->record_id] = $event->created_at;
            }
        }
        return $times;
    }

    private function notification(User $user, string $id, string $type, string $label, string $description, string $link, $timestamp): array
    {
        return [
            'id' => $id, 'type' => $type, 'label' => $label, 'description' => $description,
            'link' => $link, 'occurred_at' => $timestamp?->toIso8601String(),
            'unread' => $user->alerts_last_seen_at === null || ($timestamp !== null && $timestamp->gt($user->alerts_last_seen_at)),
        ];
    }
}
