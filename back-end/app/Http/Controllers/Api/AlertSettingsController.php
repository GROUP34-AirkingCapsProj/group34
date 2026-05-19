<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserAlertSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AlertSettingsController extends Controller
{
    /**
     * Get current user's alert settings including unread counts.
     */
    public function index(): JsonResponse
    {
        $userId = Auth::id();
        if (! $userId) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $settings = UserAlertSettings::getOrCreateForUser($userId);
        $lastSeen = $settings?->pending_approvals_last_seen_at;

        // Pending POs — status_id 7 = PO Pending
        $pendingPoCount = \App\Models\PurchaseOrder::where('status_id', 7)->count();

        // Pending PRs — status_id 13 = PR Pending
        $pendingPrCount = \App\Models\PurchaseRequest::where('status_id', 13)->count();

        // Pending item registrations
        $pendingProductCount = \App\Models\PendingProduct::where('status', 'pending')->count();

        $totalPending = $pendingPoCount + $pendingPrCount + $pendingProductCount;

        // Unread = items created after last_seen_at
        if ($lastSeen) {
            $unreadPoCount      = \App\Models\PurchaseOrder::where('status_id', 7)
                ->where('created_at', '>', $lastSeen)->count();
            $unreadPrCount      = \App\Models\PurchaseRequest::where('status_id', 13)
                ->where('created_at', '>', $lastSeen)->count();
            $unreadProductCount = \App\Models\PendingProduct::where('status', 'pending')
                ->where('created_at', '>', $lastSeen)->count();
            $unreadCount = $unreadPoCount + $unreadPrCount + $unreadProductCount;
        } else {
            $unreadPoCount = $pendingPoCount;
            $unreadCount   = $totalPending;
        }

        // Low stock: inventory qty <= safety_stock (or recommended_stocks as fallback)
        $lowStockCount = DB::table('inventory as i')
            ->join('products as p', 'p.product_id', '=', 'i.product_id')
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
            ->where('i.quantity_on_hand', '<=', 0)
            ->count();

        // Overdue POs — past expected_delivery_date, not yet Rejected (9) or Completed (11)
        $overduePoCount = \App\Models\PurchaseOrder::whereNotNull('expected_delivery_date')
            ->whereDate('expected_delivery_date', '<', now()->toDateString())
            ->whereNotIn('status_id', [9, 11])
            ->count();

        $badgeCount = $unreadCount + $lowStockCount + $outOfStockCount + $overduePoCount;

        // Structured alert list — text labels only, no emoji
        $alerts = array_values(array_filter([
            $pendingPoCount > 0 ? [
                'type'     => 'pending_po',
                'label'    => $pendingPoCount . ' purchase order(s) pending approval',
                'link'     => '/admin/purchase-orders',
                'count'    => $pendingPoCount,
                'severity' => 'warning',
            ] : null,
            $pendingPrCount > 0 ? [
                'type'     => 'pending_pr',
                'label'    => $pendingPrCount . ' purchase request(s) awaiting approval',
                'link'     => '/admin/purchase-requests',
                'count'    => $pendingPrCount,
                'severity' => 'warning',
            ] : null,
            $pendingProductCount > 0 ? [
                'type'     => 'pending_product',
                'label'    => $pendingProductCount . ' item registration(s) pending',
                'link'     => '/admin/items',
                'count'    => $pendingProductCount,
                'severity' => 'info',
            ] : null,
            $overduePoCount > 0 ? [
                'type'     => 'overdue_po',
                'label'    => $overduePoCount . ' purchase order(s) are overdue',
                'link'     => '/admin/purchase-orders',
                'count'    => $overduePoCount,
                'severity' => 'danger',
            ] : null,
            $lowStockCount > 0 ? [
                'type'     => 'low_stock',
                'label'    => $lowStockCount . ' product(s) at or below safety stock level',
                'link'     => '/admin/inventory',
                'count'    => $lowStockCount,
                'severity' => 'warning',
            ] : null,
            $outOfStockCount > 0 ? [
                'type'     => 'out_of_stock',
                'label'    => $outOfStockCount . ' product(s) are out of stock',
                'link'     => '/admin/inventory',
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
                'pending_approvals_last_seen_at' => $settings?->pending_approvals_last_seen_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Mark all pending approvals as read (update last_seen_at to now).
     */
    public function markAllRead(): JsonResponse
    {
        $userId = Auth::id();
        
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }
        
        $settings = UserAlertSettings::getOrCreateForUser($userId);
        
        if (!$settings) {
            return response()->json(['success' => false, 'message' => 'Failed to get or create alert settings'], 500);
        }

        $settings->markPendingApprovalsSeen();
        
        return response()->json([
            'success' => true,
            'message' => 'All alerts marked as read',
            'data' => [
                'pending_approvals_last_seen_at' => $settings->pending_approvals_last_seen_at?->toIso8601String(),
                'unread_count' => 0,
            ],
        ]);
    }
}
