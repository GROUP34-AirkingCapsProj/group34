<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\LoginActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuditTrailController extends Controller
{
    private const ACTION_LABELS = [
        'login'                => 'User Login',
        'logout'               => 'User Logout',
        'register'             => 'User Registered',
        'create'               => 'Record Created',
        'update'               => 'Record Updated',
        'delete'               => 'Record Deleted',
        'barcode_correction'   => 'Barcode Correction',
        'receiving'            => 'Stock Received',
        'issuance_completed'   => 'Issuance Completed',
        'inventory_adjustment' => 'Inventory Adjusted',
        'stock_transfer'       => 'Stock Transferred',
        'unauthorized_access'  => 'Unauthorized Access',
        'login_failed'         => 'Failed Login',
        'password_changed'         => 'Password Changed',
        'password_changed_by_admin' => 'Password Set by Admin',
        'password_reset_requested'  => 'Password Reset Requested',
        'password_reset_completed'  => 'Password Reset Completed',
        'reset_code_issued'         => 'Authorization Code Issued',
        'reset_code_revoked'        => 'Authorization Code Revoked',
    ];

    private const TABLE_LABELS = [
        'purchase_requests'        => 'Purchase Request',
        'purchase_request_details' => 'PR Line Item',
        'purchase_orders'          => 'Purchase Order',
        'purchase_order_details'   => 'PO Line Item',
        'receivings'               => 'Receiving Session',
        'receiving_details'        => 'Receiving Item',
        'issuances'                => 'Issuance',
        'issuance_details'         => 'Issuance Item',
        'scan_corrections'         => 'Barcode Correction',
        'adjustments'              => 'Adjustment',
        'adjustment_details'       => 'Adjustment Item',
        'transfers'                => 'Transfer',
        'transfer_details'         => 'Transfer Item',
        'inventory'                => 'Inventory',
        'item_serials'             => 'Serial Number',
        'products'                 => 'Product',
        'warranty_records'         => 'Warranty Record',
        'users'                    => 'User',
        'budget_allocations'       => 'Budget Allocation',
        'sales'                    => 'Sale',
        'sale_details'             => 'Sale Item',
        'purchase_returns'         => 'Purchase Return',
        'purchase_return_details'  => 'Purchase Return Item',
        'delivery_receipts'        => 'Delivery Receipt',
        'locations'                => 'Location',
        'customers'                => 'Customer',
    ];

    private const SKIP_FIELDS = ['created_at', 'updated_at', 'deleted_at'];

    /** Password-lifecycle actions written by App\Services\PasswordAuditLogger. */
    private const PASSWORD_ACTIONS = [
        'self_change'  => 'password_changed',
        'admin_change' => 'password_changed_by_admin',
        'requested'    => 'password_reset_requested',
        'completed'    => 'password_reset_completed',
        'code_issued'  => 'reset_code_issued',
        'code_revoked' => 'reset_code_revoked',
    ];

    /**
     * Legacy paginated audit feed used by the Audit Trail page.
     * Unauthorized rows are only unrecognized accounts (no matching user) — shown as "unknown".
     */
    public function legacyIndex(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 50), 5), 200);

        $auditRows = AuditTrail::with('user')
            ->orderByDesc('created_at')
            ->limit(300)
            ->get()
            // Drop unauthorized rows tied to known users (wrong-password / permission noise).
            ->filter(function (AuditTrail $row) {
                if ($row->action !== 'unauthorized_access') {
                    return true;
                }

                return $row->user_id === null;
            })
            ->values();

        // Historical unrecognized attempts: failed login with no matching user_id.
        $failedUnknown = LoginActivity::query()
            ->where('success', false)
            ->whereNull('user_id')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $unauthorizedTimestamps = [];
        foreach ($auditRows->where('action', 'unauthorized_access') as $row) {
            $ts = $row->created_at?->timestamp ?? 0;
            $ip = (string) ($row->ip_address ?? '');
            $unauthorizedTimestamps[$ip . '|' . $ts] = true;
            $unauthorizedTimestamps[$ip . '|' . ($ts - 1)] = true;
            $unauthorizedTimestamps[$ip . '|' . ($ts + 1)] = true;
        }

        $merged = $auditRows->values();

        foreach ($failedUnknown as $fail) {
            $ts = $fail->created_at?->timestamp ?? 0;
            $ip = (string) ($fail->ip_address ?? '');
            if (isset($unauthorizedTimestamps[$ip . '|' . $ts])) {
                continue;
            }

            $merged->push((object) [
                'audit_id' => 'login-fail-' . $fail->id,
                'user_id' => null,
                'action' => 'unauthorized_access',
                'table_name' => 'security',
                'record_id' => null,
                'old_values' => null,
                'new_values' => [
                    'attempt_type' => 'unrecognized_account',
                    'reason' => 'Unrecognized account attempted to access the system.',
                    'attempted_email' => 'unknown',
                ],
                'ip_address' => $fail->ip_address,
                'user_agent' => $fail->user_agent,
                'created_at' => $fail->created_at,
                'updated_at' => $fail->created_at,
                'user' => null,
                'is_synthetic' => true,
            ]);
        }

        $sorted = $merged->sortByDesc(function ($row) {
            $ts = $row->created_at ?? null;
            if ($ts instanceof Carbon) {
                return $ts->timestamp;
            }
            if ($ts) {
                return Carbon::parse($ts)->timestamp;
            }

            return 0;
        })->values();

        $total = $sorted->count();
        $page = max((int) $request->input('page', 1), 1);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        $data = $slice->map(function ($row) {
            if ($row instanceof AuditTrail) {
                return $this->serializeLegacyRow($row);
            }

            return [
                'audit_id' => $row->audit_id,
                'user_id' => null,
                'action' => 'unauthorized_access',
                'table_name' => 'security',
                'record_id' => null,
                'old_values' => null,
                'new_values' => [
                    'attempt_type' => 'unrecognized_account',
                    'reason' => 'Unrecognized account attempted to access the system.',
                    'attempted_email' => 'unknown',
                ],
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'created_at' => $row->created_at instanceof Carbon
                    ? $row->created_at->toIso8601String()
                    : ($row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null),
                'updated_at' => $row->updated_at instanceof Carbon
                    ? $row->updated_at->toIso8601String()
                    : ($row->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : null),
                'user' => null,
            ];
        });

        return response()->json([
            'current_page' => $page,
            'data' => $data,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeLegacyRow(AuditTrail $row): array
    {
        $newValues = $row->new_values;
        if ($row->action === 'unauthorized_access') {
            $newValues = [
                'attempt_type' => 'unrecognized_account',
                'reason' => 'Unrecognized account attempted to access the system.',
                'attempted_email' => 'unknown',
            ];
        }

        return [
            'audit_id' => $row->audit_id,
            'user_id' => $row->action === 'unauthorized_access' ? null : $row->user_id,
            'action' => $row->action,
            'table_name' => $row->table_name,
            'record_id' => $row->action === 'unauthorized_access' ? null : $row->record_id,
            'old_values' => $row->old_values,
            'new_values' => $newValues,
            'ip_address' => $row->ip_address,
            'user_agent' => $row->user_agent,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
            'user' => $row->action === 'unauthorized_access' ? null : $row->user,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 50);
        $perPage = min(max($perPage, 5), 200);

        $query = AuditTrail::with('user')
            ->orderBy('created_at', 'desc');

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }
        if ($request->filled('table_name')) {
            $query->where('table_name', $request->input('table_name'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('action', 'like', $term)
                  ->orWhere('table_name', 'like', $term)
                  ->orWhere('record_id', 'like', $term);
            });
        }

        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(fn($row) => $this->formatRow($row));

        return response()->json([
            'success' => true,
            'data'    => $items,
            'pagination' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(int $auditId): JsonResponse
    {
        $row = AuditTrail::with('user')->findOrFail($auditId);

        return response()->json([
            'success' => true,
            'data'    => $this->formatRow($row),
        ]);
    }

    public function filterOptions(): JsonResponse
    {
        $actions = AuditTrail::select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->map(fn($a) => [
                'value' => $a,
                'label' => self::ACTION_LABELS[$a] ?? ucwords(str_replace('_', ' ', $a)),
            ]);

        $tables = AuditTrail::select('table_name')
            ->distinct()
            ->whereNotNull('table_name')
            ->orderBy('table_name')
            ->pluck('table_name')
            ->map(fn($t) => [
                'value' => $t,
                'label' => self::TABLE_LABELS[$t] ?? ucwords(str_replace('_', ' ', $t)),
            ]);

        $users = DB::table('audit_trail as at')
            ->join('users as u', 'u.user_id', '=', 'at.user_id')
            ->selectRaw("u.user_id, CONCAT(u.first_name, ' ', u.last_name) as full_name")
            ->distinct()
            ->orderBy('full_name')
            ->get()
            ->map(fn($u) => [
                'value' => $u->user_id,
                'label' => trim($u->full_name) ?: 'Unknown',
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'actions' => $actions,
                'tables'  => $tables,
                'users'   => $users,
            ],
        ]);
    }

    private function formatRow(AuditTrail $row): array
    {
        $action      = $row->action ?? '';
        $tableName   = $row->table_name ?? '';
        $actionLabel = self::ACTION_LABELS[$action] ?? ucwords(str_replace('_', ' ', $action));
        $moduleLabel = $tableName
            ? (self::TABLE_LABELS[$tableName] ?? ucwords(str_replace('_', ' ', $tableName)))
            : null;

        $userName = '';
        if ($row->user) {
            $userName = trim(($row->user->first_name ?? '') . ' ' . ($row->user->last_name ?? ''));
            if ($userName === '') {
                $userName = $row->user->username ?? 'System';
            }
        }

        return [
            'audit_id'       => $row->audit_id,
            'user_id'        => $row->user_id,
            'user_full_name' => $userName ?: 'System',
            'action'         => $action,
            'action_label'   => $actionLabel,
            'table_name'     => $tableName ?: null,
            'module_label'   => $moduleLabel,
            'record_id'      => $row->record_id,
            'old_values'     => $row->old_values,
            'new_values'     => $row->new_values,
            'change_summary' => $this->buildChangeSummary($action, $row->old_values, $row->new_values, $row->record_id),
            'ip_address'     => $row->ip_address,
            'user_agent'     => $row->user_agent,
            'created_at'     => $row->created_at?->toIso8601String(),
        ];
    }

    private function buildChangeSummary(string $action, ?array $oldVals, ?array $newVals, mixed $recordId): string
    {
        $skipSet = \array_flip(self::SKIP_FIELDS);

        if ($action === 'update' && $newVals) {
            $changed = \array_keys(\array_diff_key($newVals, $skipSet));
            if (empty($changed)) {
                return 'Record updated (no tracked fields changed)';
            }
            $labels = \array_map(fn($f) => \str_replace(['_id', '_'], ['', ' '], $f), \array_slice($changed, 0, 4));
            $more   = \count($changed) > 4 ? ' and ' . (\count($changed) - 4) . ' more' : '';
            return 'Changed: ' . \implode(', ', $labels) . $more;
        }

        if ($action === 'create' && $newVals) {
            $vals  = \array_filter(\array_diff_key($newVals, $skipSet), fn($v) => $v !== null && $v !== '');
            $parts = [];
            foreach (\array_slice($vals, 0, 3, true) as $k => $v) {
                $label   = \str_replace(['_id', '_'], ['', ' '], $k);
                $display = \strlen((string) $v) > 25 ? \substr((string) $v, 0, 22) . '...' : $v;
                $parts[] = $label . ': ' . $display;
            }
            return $parts ? \implode(', ', $parts) : 'New record created';
        }

        if ($action === 'delete') {
            return $recordId ? 'Record #' . $recordId . ' permanently removed' : 'Record permanently removed';
        }

        if ($action === 'login_failed') {
            return (string) ($newVals['reason'] ?? 'Sign-in attempt failed.');
        }

        if ($action === self::PASSWORD_ACTIONS['requested']) {
            return 'Reset code sent to the registered recovery email';
        }

        if ($action === self::PASSWORD_ACTIONS['completed']) {
            return ($newVals['method'] ?? null) === 'admin_authorization_code'
                ? 'Password reset using an admin authorization code'
                : 'Password reset using an emailed reset code';
        }

        if ($action === self::PASSWORD_ACTIONS['self_change']) {
            return ($newVals['method'] ?? null) === 'forced_change_at_login'
                ? 'Temporary password replaced at first login'
                : 'User changed their own password';
        }

        if ($action === self::PASSWORD_ACTIONS['admin_change']) {
            return 'Admin set a temporary password for this account';
        }

        if ($action === self::PASSWORD_ACTIONS['code_issued']) {
            $exp = $newVals['expires_at'] ?? null;
            return 'Admin issued an authorization code' . ($exp ? ' (expires ' . $exp . ')' : '');
        }

        if ($action === self::PASSWORD_ACTIONS['code_revoked']) {
            return 'Admin revoked an authorization code';
        }

        if ($action === 'barcode_correction') {
            $old = $oldVals['original_barcode'] ?? null;
            $new = $newVals['corrected_barcode'] ?? null;
            if ($old && $new) {
                return 'Barcode corrected from ' . $old . ' to ' . $new;
            }
            return 'Barcode correction applied';
        }

        if ($action === 'login')  return 'User signed in';
        if ($action === 'logout') return 'User signed out';

        if ($action === 'unauthorized_access') {
            return 'Unrecognized account attempted to access the system.';
        }

        return \ucwords(\str_replace('_', ' ', $action));
    }
}
