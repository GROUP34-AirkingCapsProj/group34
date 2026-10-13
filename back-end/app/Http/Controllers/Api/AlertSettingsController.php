<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AlertSettingsController extends Controller
{
    /**
     * Get current user's alert settings including unread counts.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }
        // Location scoping: only warehouse_personnel=4 is read-scoped and sees
        // just its own location's alerts. All-location readers (admin=1,
        // inventory_analyst=2, branch_manager=3, auditor=5) see every location —
        // no filter applied.
        $scoped = ! $user->readsAllLocations();
        $locId  = $user->scopedLocationId();

        $lastSeen = $user->alerts_last_seen_at;

        // Pending POs — status_id 7 = PO Pending
        $pendingPoCount = \App\Models\PurchaseOrder::where('status_id', 7)
            ->when($scoped, fn ($q) => $q->where('location_id', $locId))
            ->count();

        // Pending PRs — status_id 13 = PR Pending
        $pendingPrCount = \App\Models\PurchaseRequest::where('status_id', 13)
            ->when($scoped, fn ($q) => $q->where('location_id', $locId))
            ->count();

        // Pending item registrations
        $pendingProductCount = \App\Models\PendingProduct::where('status', 'pending')
            ->when($scoped, fn ($q) => $q->where('opening_location_id', $locId))
            ->count();

        $totalPending = $pendingPoCount + $pendingPrCount + $pendingProductCount;

        // Unread = items created after last_seen_at
        if ($lastSeen) {
            $unreadPoCount      = \App\Models\PurchaseOrder::where('status_id', 7)
                ->when($scoped, fn ($q) => $q->where('location_id', $locId))
                ->where('created_at', '>', $lastSeen)->count();
            $unreadPrCount      = \App\Models\PurchaseRequest::where('status_id', 13)
                ->when($scoped, fn ($q) => $q->where('location_id', $locId))
                ->where('created_at', '>', $lastSeen)->count();
            $unreadProductCount = \App\Models\PendingProduct::where('status', 'pending')
                ->when($scoped, fn ($q) => $q->where('opening_location_id', $locId))
                ->where('created_at', '>', $lastSeen)->count();
            $unreadCount = $unreadPoCount + $unreadPrCount + $unreadProductCount;
        } else {
            $unreadPoCount = $pendingPoCount;
            $unreadCount   = $totalPending;
        }

        // Low stock: inventory qty <= safety_stock (or recommended_stocks as fallback)
        $lowStockCount = DB::table('inventory as i')
            ->join('products as p', 'p.product_id', '=', 'i.product_id')
            ->when($scoped, fn ($q) => $q->where('i.location_id', $locId))
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotNull('p.safety_stock')
                          ->whereRaw('i.quantity_on_hand <= p.safety_stock');
                })->orWhere(function ($inner) {
                    $inner->whereNull('p.safety_stock')
                          ->where('p.recommended_stocks', '>', 0)
                          ->whereRaw('i.quantity_on_hand <= p.recommended_stocks');
                });
            })
            ->count();

        // Out of stock
        $outOfStockCount = DB::table('inventory as i')
            ->when($scoped, fn ($q) => $q->where('i.location_id', $locId))
            ->where('i.quantity_on_hand', '<=', 0)
            ->count();

        // Overdue POs — past expected_delivery_date, not yet Rejected (9) or Completed (11)
        $overduePoCount = \App\Models\PurchaseOrder::whereNotNull('expected_delivery_date')
            ->whereDate('expected_delivery_date', '<', now()->toDateString())
            ->whereNotIn('status_id', [9, 11])
            ->when($scoped, fn ($q) => $q->where('location_id', $locId))
            ->count();

        // PO verification queue — surfaced ONLY to users who may verify (auditor by
        // default, or anyone the admin grants the permission to), scoped the same
        // way the rest of that user's data is.
        $resolver = app(\App\Support\PermissionResolver::class);
        $uid = (int) $user->user_id;

        $pendingPoVerifyCount = $resolver->canUser($uid, 'purchase_order_verification', 'verify')
            ? \App\Models\PurchaseOrder::where('verification_status', 'pending')
                ->where('status_id', 7) // still Pending — awaiting the checker before approval
                ->when($scoped, fn ($q) => $q->where('location_id', $locId))
                ->count()
            : 0;

        $warehouseNotifications = app(\App\Services\WarehouseNotificationService::class)->forUser($user);
        $warehouseUnread = count(array_filter($warehouseNotifications, fn ($n) => $n['unread']));
        $unreadCount += $warehouseUnread;
        $badgeCount = $unreadCount + $lowStockCount + $outOfStockCount + $overduePoCount
            + $pendingPoVerifyCount;

        // Structured alert list — text labels only, no emoji
        $alerts = array_values(array_filter([
            $pendingPoCount > 0 ? [
                'type'     => 'pending_po',
                'label'    => $pendingPoCount . ' purchase order(s) pending approval',
                'link'     => '/purchase-orders',
                'count'    => $pendingPoCount,
                'severity' => 'warning',
            ] : null,
            $pendingPrCount > 0 ? [
                'type'     => 'pending_pr',
                'label'    => $pendingPrCount . ' purchase request(s) awaiting approval',
                'link'     => '/purchase-requests?tab=create',
                'count'    => $pendingPrCount,
                'severity' => 'warning',
            ] : null,
            $pendingProductCount > 0 ? [
                'type'     => 'pending_product',
                'label'    => $pendingProductCount . ' item registration(s) pending',
                'link'     => '/items',
                'count'    => $pendingProductCount,
                'severity' => 'info',
            ] : null,
            $pendingPoVerifyCount > 0 ? [
                'type'     => 'po_verification',
                'label'    => $pendingPoVerifyCount . ' purchase order(s) awaiting your verification',
                'link'     => '/draft-po-creator',
                'count'    => $pendingPoVerifyCount,
                'severity' => 'warning',
            ] : null,
            $overduePoCount > 0 ? [
                'type'     => 'overdue_po',
                'label'    => $overduePoCount . ' purchase order(s) are overdue',
                'link'     => '/purchase-orders',
                'count'    => $overduePoCount,
                'severity' => 'danger',
            ] : null,
            $lowStockCount > 0 ? [
                'type'     => 'low_stock',
                'label'    => $lowStockCount . ' product(s) at or below safety stock level',
                'link'     => '/purchase-requests',
                'count'    => $lowStockCount,
                'severity' => 'warning',
            ] : null,
            $outOfStockCount > 0 ? [
                'type'     => 'out_of_stock',
                'label'    => $outOfStockCount . ' product(s) are out of stock',
                'link'     => '/inventory',
                'count'    => $outOfStockCount,
                'severity' => 'danger',
            ] : null,
        ]));

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count'                   => $unreadCount,
                'badge_count'                    => $badgeCount,
                'total_pending'                  => $totalPending,
                'pending_po_count'               => $pendingPoCount,
                'pending_pr_count'               => $pendingPrCount,
                'pending_product_count'          => $pendingProductCount,
                'low_stock_count'                => $lowStockCount,
                'out_of_stock_count'             => $outOfStockCount,
                'overdue_po_count'               => $overduePoCount,
                'alerts'                         => $alerts,
                'warehouse_notifications'        => $warehouseNotifications,
                'warehouse_unread_count'         => $warehouseUnread,
                'warehouse_unread_ids'           => array_values(array_column(array_filter($warehouseNotifications, fn ($n) => $n['unread']), 'id')),
                'pending_approvals_last_seen_at' => $user->alerts_last_seen_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Mark all pending approvals as read (update last_seen_at to now).
     */
    public function markAllRead(): JsonResponse
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $user->alerts_last_seen_at = now();
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'All alerts marked as read',
            'data' => [
                'pending_approvals_last_seen_at' => $user->alerts_last_seen_at?->toIso8601String(),
                'unread_count' => 0,
            ],
        ]);
    }
}
