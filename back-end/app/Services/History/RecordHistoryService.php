<?php

namespace App\Services\History;

use App\Models\AuditTrail;

class RecordHistoryService
{
    /**
     * Fields that never generate a milestone or a change row.
     * `converted_to_po_id` is stored as a raw PO id (e.g. "55") and is already
     * represented by the friendly "Converted to PO {po_number}" milestone, so we
     * drop the noisy duplicate row here.
     */
    private const SKIP = ['created_at', 'updated_at', 'deleted_at', 'laravel_through_key', 'converted_to_po_id'];

    /**
     * Fold this record's audit rows into an ordered list of curated milestones.
     *
     * @return array<int, array{type:string,label:string,actor_name:string,timestamp:?string,changes:array}>
     */
    public function forRecord(string $tableName, int $recordId): array
    {
        $rows = AuditTrail::with('user')
            ->where('table_name', $tableName)
            ->where('record_id', $recordId)
            ->orderBy('created_at', 'asc')
            ->orderBy('audit_id', 'asc')
            ->get();

        $milestones = [];
        foreach ($rows as $row) {
            $milestone = $this->rowToMilestone($row);
            // Drop no-op edits: an update that only touched skip-fields produces
            // an 'edited' milestone with no meaningful change rows.
            if ($milestone['type'] === 'edited' && empty($milestone['changes'])) {
                continue;
            }
            $milestones[] = $milestone;
        }
        return array_values($milestones);
    }

    private function rowToMilestone(AuditTrail $row): array
    {
        $old = $row->old_values ?? [];
        $new = $row->new_values ?? [];
        $changes = $this->buildChanges($old, $new);

        [$type, $label] = $this->classify($row->action, $old, $new);

        return [
            'type'       => $type,
            'label'      => $label,
            'actor_name' => $this->actorName($row),
            'timestamp'  => $row->created_at?->toIso8601String(),
            'changes'    => $changes,
        ];
    }

    /** @return array{0:string,1:string} [type, label] */
    private function classify(string $action, array $old, array $new): array
    {
        if ($action === 'create') {
            return ['created', 'Created'];
        }
        if ($action === 'delete') {
            return ['archived', 'Archived'];
        }

        // update — inspect what meaningfully changed
        // status_id arrives already label-resolved by the Auditable trait, so this
        // is the human status name (e.g. "Approved"), not a numeric id.
        if (array_key_exists('status_id', $new)) {
            return ['status', (string) $new['status_id']];
        }
        if (array_key_exists('verification_status', $new)) {
            $v = strtolower((string) $new['verification_status']);
            if ($v === 'verified') return ['verified', 'Verified'];
            if ($v === 'rejected') return ['rejected', 'Rejected'];
            return ['status', 'Verification ' . $new['verification_status']];
        }
        return ['edited', 'Edited'];
    }

    private function buildChanges(array $old, array $new): array
    {
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $changes = [];
        foreach ($keys as $key) {
            if (in_array($key, self::SKIP, true)) {
                continue;
            }
            $changes[] = [
                'field' => str_replace(['_id', '_'], ['', ' '], $key),
                'from'  => $old[$key] ?? null,
                'to'    => $new[$key] ?? null,
            ];
        }
        return $changes;
    }

    private function actorName(AuditTrail $row): string
    {
        $u = $row->user;
        if (! $u) {
            return 'System';
        }
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        return $name !== '' ? $name : ($u->username ?? 'System');
    }
}
