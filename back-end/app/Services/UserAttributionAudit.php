<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Answers one question: would permanently deleting this account destroy history?
 *
 * Nearly every foreign key pointing at `users` is ON DELETE SET NULL, so a
 * force delete does not fail -- it succeeds and quietly anonymises every row
 * the person was attributed on. Two keys out of forty-nine are RESTRICT, which
 * means the database refuses the handful of cases it happens to cover and waves
 * the rest through. This class is the rule the database cannot express.
 *
 * The column list is hand-written, and
 * PermanentDeleteGuardTest::test_every_foreign_key_to_users_is_accounted_for
 * reads the live schema and fails when a new foreign key to `users` is not
 * classified here. Without that test this list would rot into a guard that
 * protects whatever the schema looked like on the day it was written.
 */
class UserAttributionAudit
{
    /**
     * Columns that attribute real work to a person. A single row in any of them
     * means permanent deletion would rewrite the record of who did what.
     *
     * @var array<string, list<string>>
     */
    public const ATTRIBUTION = [
        'activity_log'                 => ['user_id'],
        'adjustments'                  => ['adjusted_by', 'approved_by', 'created_by'],
        'archive_logs'                 => ['triggered_by_user_id'],
        'backup_logs'                  => ['triggered_by_user_id'],
        'barcode_scans'                => ['user_id'],
        'budget_allocations'           => ['created_by'],
        'budget_transactions'          => ['performed_by'],
        'delivery_receipts'            => ['issued_by'],
        'incident_reports'             => ['reported_by', 'reviewed_by'],
        'inventory_scan_events'        => ['user_id'],
        'issuances'                    => ['approved_by', 'issued_by', 'issued_to_user_id'],
        'item_serial'                  => ['last_printed_by', 'scanned_by'],
        'pending_products'             => ['approved_by', 'created_by'],
        'product_sets'                 => ['created_by', 'updated_by'],
        'profit_loss'                  => ['approved_by', 'recorded_by'],
        'purchase_orders'              => ['approved_by', 'created_by', 'received_by', 'verified_by'],
        'purchase_requests'            => ['approved_by', 'requested_by'],
        'purchase_returns'             => ['approved_by', 'requested_by'],
        'receivings'                   => ['received_by'],
        'report_runs'                  => ['generated_by'],
        'sales'                        => ['sold_by'],
        'scan_corrections'             => ['approved_by', 'corrected_by'],
        'stock_parameter_suggestions'  => ['reviewed_by'],
        'transfers'                    => ['approved_by', 'received_by', 'requested_by'],
        'transfer_tracking'            => ['recorded_by'],
        'warranty_claims'              => ['created_by'],
        'warranty_records'             => ['claimed_by'],
    ];

    /**
     * Security records, which cannot be counted flatly.
     *
     * Anyone who types an address into the login form writes rows here against
     * whichever account owns it. Counting those as history would mean a
     * stranger's typo could make a mistyped, never-used account undeletable --
     * so only a SUCCESSFUL login counts as the account having been used, and in
     * the audit trail only rows that are not login bookkeeping.
     *
     * @var array<string, list<string>>
     */
    public const SECURITY_LOG = [
        'audit_trail'      => ['user_id'],
        'login_activities' => ['user_id'],
    ];

    /**
     * Account plumbing: rows that exist only to serve the account and are
     * removed with it by ON DELETE CASCADE. Nothing here is history.
     *
     * @var array<string, list<string>>
     */
    public const PLUMBING = [
        'password_reset_codes'      => ['user_id'],
        'user_locations'            => ['user_id'],
        'user_permission_overrides' => ['user_id'],
    ];

    /**
     * Human names for the message an admin reads. Singular -- pluralised at the
     * point of use -- and worded the way the UI words them, not the way the
     * tables are named.
     */
    private const LABELS = [
        'activity_log'                => 'activity log entry',
        'adjustments'                 => 'stock adjustment',
        'archive_logs'                => 'archive run',
        'backup_logs'                 => 'backup run',
        'barcode_scans'               => 'barcode scan',
        'budget_allocations'          => 'budget allocation',
        'budget_transactions'         => 'budget transaction',
        'delivery_receipts'           => 'delivery receipt',
        'incident_reports'            => 'incident report',
        'inventory_scan_events'       => 'inventory scan',
        'issuances'                   => 'issuance',
        'item_serial'                 => 'serialised item',
        'pending_products'            => 'pending product',
        'product_sets'                => 'product set',
        'profit_loss'                 => 'profit & loss record',
        'purchase_orders'             => 'purchase order',
        'purchase_requests'           => 'purchase request',
        'purchase_returns'            => 'purchase return',
        'receivings'                  => 'receiving record',
        'report_runs'                 => 'generated report',
        'sales'                       => 'sale',
        'scan_corrections'            => 'scan correction',
        'stock_parameter_suggestions' => 'stock parameter review',
        'transfers'                   => 'transfer',
        'transfer_tracking'           => 'transfer tracking entry',
        'warranty_claims'             => 'warranty claim',
        'warranty_records'            => 'warranty record',
        'audit_trail'                 => 'audit trail entry',
        'login_activities'            => 'completed sign-in',
    ];

    /**
     * What this account is attributed on, as label => row count. Empty means the
     * account was never used and can be deleted without losing anything.
     *
     * @return array<string, int>
     */
    public function blockers(User $user): array
    {
        $found = [];

        foreach (self::ATTRIBUTION as $table => $columns) {
            $count = DB::table($table)
                ->where(function ($query) use ($columns, $user) {
                    foreach ($columns as $column) {
                        $query->orWhere($column, $user->user_id);
                    }
                })
                ->count();

            if ($count > 0) {
                $found[self::LABELS[$table]] = $count;
            }
        }

        $signIns = DB::table('login_activities')
            ->where('user_id', $user->user_id)
            ->where('success', true)
            ->count();

        if ($signIns > 0) {
            $found[self::LABELS['login_activities']] = $signIns;
        }

        // Login bookkeeping (failed attempts, issued codes, sign-in and
        // sign-out) is filed under table_name 'security' and says nothing about
        // the account having been used for work.
        $audited = DB::table('audit_trail')
            ->where('user_id', $user->user_id)
            ->where(function ($query) {
                $query->whereNull('table_name')->orWhere('table_name', '!=', 'security');
            })
            ->whereNotIn('action', ['login', 'logout'])
            ->count();

        if ($audited > 0) {
            $found[self::LABELS['audit_trail']] = $audited;
        }

        return $found;
    }

    /**
     * "2 purchase orders, 1 stock adjustment" -- the part of the refusal that
     * tells the admin what they were about to erase.
     */
    public function describe(array $blockers): string
    {
        $parts = [];

        foreach ($blockers as $label => $count) {
            $parts[] = $count . ' ' . $label . ($count === 1 ? '' : 's');
        }

        if (count($parts) <= 1) {
            return implode('', $parts);
        }

        $last = array_pop($parts);

        return implode(', ', $parts) . ' and ' . $last;
    }

    /**
     * The whole refusal, including the way out. A guard that only says no sends
     * the admin back to the thing it just blocked.
     */
    public function refusalMessage(array $blockers): string
    {
        return 'This account cannot be permanently deleted: it is still credited on '
            . $this->describe($blockers) . ', and deleting it would strip that work of '
            . 'its owner. To free the email address and username for a new account, use '
            . '"Release email & username" instead -- it retires them while keeping the '
            . "person's history intact.";
    }
}
