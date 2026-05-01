<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserAlertSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AlertSettingsController extends Controller
{
    /**
     * Get current user's alert settings including unread counts.
     */
    public function index(): JsonResponse
    {
        $userId = Auth::id();
        
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }
        
        $settings = UserAlertSettings::getOrCreateForUser($userId);
        
        if (!$settings) {
            return response()->json(['success' => false, 'message' => 'Failed to get or create alert settings'], 500);
        }
        
        // Get pending counts since last seen
        $pendingPOs = \App\Models\PurchaseOrder::whereHas('status', fn($q) => $q->where('status_name', 'Pending'))->count();
        $pendingProducts = \App\Models\PendingProduct::where('status', 'pending')->count();
        $totalPending = $pendingPOs + $pendingProducts;
        
        // Calculate unread: only count items created after last_seen_at
        $lastSeen = $settings->pending_approvals_last_seen_at;
        
        if ($lastSeen) {
            $unreadPOs = \App\Models\PurchaseOrder::whereHas('status', fn($q) => $q->where('status_name', 'Pending'))
                ->where('created_at', '>', $lastSeen)
                ->count();
            
            $unreadProducts = \App\Models\PendingProduct::where('status', 'pending')
                ->where('created_at', '>', $lastSeen)
                ->count();
            
            $unreadCount = $unreadPOs + $unreadProducts;
        } else {
            // First time - show all as unread
            $unreadCount = $totalPending;
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'pending_approvals_last_seen_at' => $settings->pending_approvals_last_seen_at?->toIso8601String(),
                'total_pending' => $totalPending,
                'unread_count' => $unreadCount,
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
