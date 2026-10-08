<?php

namespace App\Reports\Definitions;

use App\Models\LoginActivity;
use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;

/**
 * Login and session activity, one row per authentication attempt.
 *
 * `login_activities` has no location_id — an authentication event does not
 * belong to a warehouse — so this report cannot be scoped the way the other
 * five are. availableTo() returns User::isCentralized() (admin,
 * inventory_analyst, auditor) rather than true: a location-scoped user is
 * not offered the card at all, rather than being offered it and either
 * hitting a 403 or, worse, seeing every location's login attempts.
 *
 * The mock page originally described this card as "System usage and login
 * statistics", but this report covers logins only: when it was written there
 * was no source for the usage half, because App\Models\ActivityLog pointed at
 * an `activity_log` table that did not exist — one of several orphaned models,
 * alongside Transaction and MaterialMovementLedger.
 *
 * That has since changed: `activity_log` was given a migration, and the
 * orphaned models were deleted (except Transaction, kept pending a fix and
 * quarantined in Tests\Feature\Schema\SchemaModelContractTest). So a usage
 * half is now buildable — it simply has not been built here yet. The
 * description remains logins-only, with a note saying so explicitly, rather
 * than silently under-delivering on the original promise.
 */
class UserActivityReport implements ReportDefinition
{
    public function key(): string
    {
        return 'user-activity';
    }

    public function title(): string
    {
        return 'User Activity Report';
    }

    public function description(): string
    {
        return 'Login and session activity';
    }

    public function parameters(): array
    {
        return [
            new ReportParameter('date_range', ReportParameter::TYPE_DATE_RANGE, 'Date Range', required: true),
            new ReportParameter('user_id', ReportParameter::TYPE_USER, 'User'),
            new ReportParameter('outcome', ReportParameter::TYPE_SELECT, 'Outcome', options: [
                'success' => 'Successful only',
                'failure' => 'Failed only',
            ]),
        ];
    }

    public function formats(): array
    {
        return ['pdf', 'csv'];
    }

    public function pdfRowCap(): int
    {
        return 5000;
    }

    /**
     * login_activities carries no location column, so there is no way to
     * scope this data by location — only centralized roles (admin,
     * inventory_analyst, auditor) may see authentication activity at all.
     */
    public function availableTo(User $user): bool
    {
        return $user->isCentralized();
    }

    public function build(ReportParameters $params, User $user): ReportDataset
    {
        $query = LoginActivity::query()->with('user');

        $from = $params->dateFrom();
        $to   = $params->dateTo();

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        if ($params->has('user_id')) {
            $query->where('user_id', $params->int('user_id'));
        }

        if ($params->has('outcome')) {
            $query->where('success', $params->get('outcome') === 'success');
        }

        $query->orderByDesc('created_at');

        $rows = [];
        $distinctUsers = [];
        $failedCount = 0;

        foreach ($query->get() as $attempt) {
            $account = $attempt->user;
            $userName = $account
                ? trim($account->first_name . ' ' . $account->last_name)
                : '—';

            if ($attempt->user_id !== null) {
                $distinctUsers[$attempt->user_id] = true;
            }

            if (! $attempt->success) {
                $failedCount++;
            }

            $rows[] = [
                'occurred_at'      => optional($attempt->created_at)->format('Y-m-d H:i:s'),
                'user'             => $userName,
                'username_attempt' => $attempt->username_attempt,
                'outcome'          => $attempt->success ? 'Success' : 'Failed',
                'failure_reason'   => $attempt->failure_reason ?? '—',
                'ip_address'       => $attempt->ip_address ?? '—',
            ];
        }

        return new ReportDataset(
            columns: [
                'occurred_at'      => 'Occurred At',
                'user'             => 'User',
                'username_attempt' => 'Username Attempted',
                'outcome'          => 'Outcome',
                'failure_reason'   => 'Failure Reason',
                'ip_address'       => 'IP Address',
            ],
            rows: $rows,
            summary: [
                'Login attempts' => number_format(count($rows)),
                'Distinct users' => number_format(count($distinctUsers)),
                'Failed attempts' => number_format($failedCount),
            ],
            notes: ['Covers authentication events only — there is no system-wide usage log to report against.'],
        );
    }
}
