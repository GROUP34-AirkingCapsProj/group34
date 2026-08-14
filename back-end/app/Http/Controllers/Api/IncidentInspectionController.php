<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidentEvidence;
use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\User;
use App\Services\IncidentItemService;
use App\Services\IncidentService;
use App\Services\WarrantyStateService;
use App\Support\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The inspection half of an incident: evidence, per-item outcomes, the
 * inspector's submission, and the admin's decision.
 *
 * Everything that mutates goes through IncidentService or IncidentItemService —
 * this controller validates, authorizes and serializes, and owns none of the
 * stock arithmetic.
 */
class IncidentInspectionController extends Controller
{
    /**
     * Exactly the shape IncidentReportController::uploadPhoto() can produce.
     *
     * An unconstrained path let a filer submit another user's avatar, or an
     * absolute URL that the frontend's resolveStorageUrl() would then fetch
     * as-is. It applies per evidence row for the same reason.
     */
    private const PHOTO_PATH_REGEX = '/^incidents\/\d{4}\/\d{2}\/[A-Za-z0-9]{40}\.(jpg|jpeg|png|webp)$/';

    /**
     * The register of non-sellable stock — what is quarantined, scrapped,
     * returned or missing. Read-only; it replaces the held-stock worklist,
     * whose Dispose button the inspection took over.
     *
     * incident_items has NO location_id: location lives on the parent report,
     * so ScopesToUserLocation (which filters a location_id COLUMN) cannot be
     * used here. Scoping joins incident_reports and filters ir.location_id, the
     * same way IncidentItem::quarantinedBatchIds() does.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = IncidentItem::query()
            ->with(['product.consumableDetail', 'expectedProduct', 'serial', 'incident.location'])
            ->join('incident_reports as ir', 'ir.incident_id', '=', 'incident_items.incident_id')
            ->select('incident_items.*');

        if (! $user->isCentralized()) {
            $scoped = $user->scopedLocationId();

            // A scoped user with no location assigned sees nothing, rather than
            // everything — the same choice ScopesToUserLocation makes.
            if ($scoped === null) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $query->where('ir.location_id', $scoped);
        }

        // Default to the live half of the register: what is still held out of
        // stock. `all` is what the filters ask for when someone wants history.
        $status = $request->query('status', IncidentItem::STATUS_QUARANTINED);
        if ($status !== 'all') {
            $query->where('incident_items.status', $status);
        }

        if ($locationId = $request->query('location_id')) {
            $query->where('ir.location_id', (int) $locationId);
        }

        if ($incidentId = $request->query('incident_id')) {
            $query->where('incident_items.incident_id', (int) $incidentId);
        }

        $items = $query->orderByDesc('incident_items.created_at')->limit(500)->get();

        return response()->json([
            'success' => true,
            'data'    => $items->map(fn (IncidentItem $i) => $this->itemPayload($i, withLocation: true))->all(),
        ]);
    }

    /**
     * One report in full: its header, its items, and its evidence.
     *
     * The read counterpart of everything this controller writes. Without it the
     * inspection screen cannot show the evidence it already attached (the photo
     * route needs an evidence_id no list endpoint returns), and the admin's
     * decision screen cannot show the findings, the skipped requirements or
     * their stated reasons — the whole point of the checklist being that an
     * incomplete report is visibly incomplete.
     *
     * Gated on `read`, not `inspect`: the admin who decides holds `resolve` and
     * `read`, and must be able to see what the inspector submitted.
     */
    public function show(int $id): JsonResponse
    {
        $incident = $this->visibleIncident($id);

        if ($incident === null) {
            return response()->json(['success' => false, 'message' => 'Incident not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->reportPayload($incident, full: true),
        ]);
    }

    /** One attachment against the checklist — a photo, or an explicit skip with a reason. */
    public function storeEvidence(Request $request, int $id): JsonResponse
    {
        $incident = $this->visibleIncident($id);

        if ($incident === null) {
            return response()->json(['success' => false, 'message' => 'Incident not found.'], 404);
        }

        $validated = $request->validate([
            'requirement'    => ['required', Rule::in(IncidentEvidence::REQUIREMENTS)],
            // One or the other, never neither: a row with no photo and no stated
            // reason records nothing at all, and would read on the admin's
            // screen as a satisfied requirement.
            'photo_path'     => ['nullable', 'required_without:skipped_reason', 'string', 'max:255',
                                 'regex:' . self::PHOTO_PATH_REGEX],
            'photo_source'   => ['nullable', Rule::in(IncidentReport::PHOTO_SOURCES)],
            'skipped_reason' => ['nullable', 'required_without:photo_path', 'string', 'max:500'],
        ]);

        $evidence = IncidentEvidence::create([
            'incident_id'    => (int) $incident->incident_id,
            'requirement'    => $validated['requirement'],
            'photo_path'     => $validated['photo_path'] ?? null,
            'photo_source'   => $validated['photo_source'] ?? null,
            // A skip and a photo are mutually exclusive: keeping a stale reason
            // beside a photo would show the admin a requirement that is both
            // satisfied and excused.
            'skipped_reason' => isset($validated['photo_path']) ? null : ($validated['skipped_reason'] ?? null),
            'uploaded_by'    => (int) Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Evidence recorded.',
            'data'    => $this->evidencePayload($evidence),
        ], 201);
    }

    /** Remove an attachment before the inspection is submitted. */
    public function destroyEvidence(int $id, int $evidenceId): JsonResponse
    {
        $incident = $this->visibleIncident($id);

        if ($incident === null) {
            return response()->json(['success' => false, 'message' => 'Incident not found.'], 404);
        }

        $evidence = IncidentEvidence::where('incident_id', $incident->incident_id)
            ->where('evidence_id', $evidenceId)
            ->first();

        if ($evidence === null) {
            return response()->json(['success' => false, 'message' => 'Evidence not found.'], 404);
        }

        $evidence->delete();

        return response()->json(['success' => true, 'message' => 'Evidence removed.']);
    }

    /**
     * Stream one evidence photo back inline, scoped to incidents the caller can
     * see. Same shape as IncidentReportController::showPhoto(): these images may
     * show named staff, stock layout, or the subject of a live loss
     * investigation, so they sit on the private `local` disk behind
     * auth:sanctum rather than under /storage/.
     */
    public function showEvidencePhoto(int $id, int $evidenceId): StreamedResponse|JsonResponse
    {
        $incident = $this->visibleIncident($id);

        $evidence = $incident === null ? null : IncidentEvidence::where('incident_id', $incident->incident_id)
            ->where('evidence_id', $evidenceId)
            ->first();

        // Defensive: storeEvidence's regex is the only thing guaranteeing the
        // path stays inside incidents/ once it has round-tripped the database.
        if ($evidence === null
            || $evidence->photo_path === null
            || ! str_starts_with($evidence->photo_path, 'incidents/')
            || ! Storage::disk('local')->exists($evidence->photo_path)) {
            return response()->json(['success' => false, 'message' => 'Photo not found.'], 404);
        }

        return Storage::disk('local')->response($evidence->photo_path);
    }

    /** The inspector's decision on one physical item. Applied only on confirmation. */
    public function setOutcome(Request $request, int $id, int $itemId): JsonResponse
    {
        $incident = $this->visibleIncident($id);

        if ($incident === null) {
            return response()->json(['success' => false, 'message' => 'Incident not found.'], 404);
        }

        $validated = $request->validate([
            'outcome' => ['required', Rule::in(IncidentItem::OUTCOMES)],
            'note'    => 'nullable|string|max:2000',
        ]);

        $item = IncidentItem::where('incident_id', $incident->incident_id)
            ->where('item_id', $itemId)
            ->first();

        if ($item === null) {
            return response()->json(['success' => false, 'message' => 'Item not found on this incident.'], 404);
        }

        // Enforced here, not only in the UI. A rule the client merely renders is
        // not a rule: the screen would hide the button while the endpoint kept
        // accepting the outcome, and a claim raised against a unit with no cover
        // is invisible until a warranty officer opens it and finds nothing to
        // claim against.
        $warranty = app(WarrantyStateService::class);
        $decision = $warranty->evaluate($item);

        if (! in_array($validated['outcome'], $decision->outcomes, true)) {
            // Two different gates reach this line, and telling them apart is the
            // whole point: one says the CUSTOMER's cover is gone, the other says
            // the SUPPLIER's is. An inspector reading the wrong one goes and
            // argues with the wrong party.
            $isSupplierGate = $validated['outcome'] === IncidentItem::OUTCOME_RETURN_TO_SUPPLIER;

            return response()->json([
                'success'           => false,
                'message'           => $isSupplierGate
                    ? $this->supplierGateMessage($decision->supplier)
                    : ($decision->customerState === WarrantyStateService::EXPIRED
                        ? 'This unit\'s warranty has expired, so it cannot be sent for a warranty claim.'
                        : 'This unit has no warranty record, so it cannot be sent for a warranty claim.'),
                'warranty_state'    => $decision->customerState,
                'supplier_warranty' => $decision->supplier->toArray(),
            ], 422);
        }

        try {
            $updated = app(IncidentItemService::class)
                ->setOutcome($item, $validated['outcome'], $validated['note'] ?? null);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            // 422, not 500: "already settled" and "unknown outcome" are both the
            // caller asking for something invalid.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Outcome recorded.',
            'data'    => $this->itemPayload($updated),
        ]);
    }

    /** Submit the findings: Open or Awaiting Inspection -> Awaiting Approval. Moves no stock. */
    public function submit(Request $request, int $id): JsonResponse
    {
        $incident = $this->visibleIncident($id);

        if ($incident === null) {
            return response()->json(['success' => false, 'message' => 'Incident not found.'], 404);
        }

        $validated = $request->validate([
            'inspection_findings'      => 'nullable|string|max:5000',
            'checked_by_asc'           => 'nullable|boolean',
            // Names as printed on the hardcopy that is signed by hand and
            // carried to the admin — a record that signing happened, not a
            // digital signature.
            'signed_by_representative' => 'nullable|string|max:150',
            'signed_by_branch_manager' => 'nullable|string|max:150',
            'signed_at'                => 'nullable|date',
        ]);

        try {
            $submitted = app(IncidentService::class)
                ->submitInspection($incident, $validated, (int) Auth::id());
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Inspection submitted for approval.',
            'data'    => $this->reportPayload($submitted),
        ]);
    }

    /** The admin's decision: confirm (stock moves here), reject, or dismiss. */
    public function decide(Request $request, int $id): JsonResponse
    {
        $incident = $this->visibleIncident($id);

        if ($incident === null) {
            return response()->json(['success' => false, 'message' => 'Incident not found.'], 404);
        }

        $validated = $request->validate([
            'action'          => ['required', Rule::in(['confirm', 'reject', 'dismiss'])],
            'rejection_note'  => 'required_if:action,reject|nullable|string|max:2000',
            'resolution_note' => 'nullable|string|max:2000',
        ]);

        $actorId = (int) Auth::id();

        if ($validated['action'] === 'confirm' && $this->wouldSelfApprove($incident, $actorId)) {
            return response()->json([
                'success' => false,
                'message' => 'You inspected this report, so someone else must confirm it.',
            ], 403);
        }

        try {
            $decided = app(IncidentService::class)
                ->decide($incident, $validated['action'], $validated, $actorId);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => match ($validated['action']) {
                'confirm' => 'Incident confirmed. The inspected outcomes have been applied to stock.',
                'reject'  => 'Returned to the inspector.',
                'dismiss' => 'Incident dismissed. The quarantine has been released.',
            },
            'data'    => $this->reportPayload($decided),
        ]);
    }

    /**
     * The two signature lines on the client's form exist so that one person
     * cannot both examine the goods and approve their write-off.
     *
     * Enforced only where somebody ELSE could actually do the confirming: on a
     * single-admin install the rule would otherwise make every report
     * permanently unclosable, which is a worse failure than self-approval.
     * "Somebody else" means an active user who both holds
     * incident_reports.resolve and can see this location.
     */
    private function wouldSelfApprove(IncidentReport $incident, int $actorId): bool
    {
        if ($incident->inspected_by === null || (int) $incident->inspected_by !== $actorId) {
            return false;
        }

        return $this->hasAnotherResolver($incident, $actorId);
    }

    private function hasAnotherResolver(IncidentReport $incident, int $actorId): bool
    {
        $activeUserStatusId = DB::table('status_lookup')
            ->where('status_category', 'user')
            ->where('status_name', 'Active')
            ->value('status_id');

        $candidates = User::query()
            ->where('user_id', '!=', $actorId)
            ->when($activeUserStatusId !== null, fn ($q) => $q->where('status_id', $activeUserStatusId))
            ->get();

        $resolver = app(PermissionResolver::class);

        foreach ($candidates as $candidate) {
            // A centralized role sees every location; a scoped one only reaches
            // reports at its own.
            $reachesLocation = $candidate->isCentralized()
                || (int) $candidate->location_id === (int) $incident->location_id;

            if ($reachesLocation
                && $resolver->canUser((int) $candidate->getKey(), 'incident_reports', 'resolve')) {
                return true;
            }
        }

        return false;
    }

    /** The incident, or null when it is outside the caller's locations. */
    private function visibleIncident(int $id): ?IncidentReport
    {
        return IncidentReport::forUser(Auth::user())->find($id);
    }

    /**
     * @param bool $full whether to include the report header and the client's
     *                   document block — the decision and inspection screens
     *                   need them; a submit/decide response is answering a
     *                   caller who already has the row on screen.
     */
    private function reportPayload(IncidentReport $incident, bool $full = false): array
    {
        $incident->load([
            'items.product.consumableDetail', 'items.expectedProduct', 'items.serial',
            'evidence', 'status', 'inspectedBy',
        ]);

        $payload = [
            'incident_id'              => (int) $incident->incident_id,
            'incident_number'          => $incident->incident_number,
            'reason'                   => $incident->reason,
            'status_id'                => (int) $incident->status_id,
            // By NAME. The ids differ between installs, so a client that
            // branches on the number is right here and wrong elsewhere; and
            // "rejected back to the inspector" is a status, not a flag, so
            // without the name it is indistinguishable from a closed report.
            'status_name'              => $incident->status?->status_name,
            'inspected_by'             => $incident->inspected_by !== null ? (int) $incident->inspected_by : null,
            'inspected_by_name'        => $incident->inspectedBy !== null
                ? trim(($incident->inspectedBy->first_name ?? '') . ' ' . ($incident->inspectedBy->last_name ?? ''))
                : null,
            'inspected_at'             => optional($incident->inspected_at)->toIso8601String(),
            'inspection_findings'      => $incident->inspection_findings,
            'checked_by_asc'           => (bool) $incident->checked_by_asc,
            'signed_by_representative' => $incident->signed_by_representative,
            'signed_by_branch_manager' => $incident->signed_by_branch_manager,
            'signed_at'                => optional($incident->signed_at)->toDateString(),
            'rejection_note'           => $incident->rejection_note,
            'resolution_note'          => $incident->resolution_note,
            'items'                    => $incident->items->map(fn (IncidentItem $i) => $this->itemPayload($i))->all(),
            'evidence'                 => $incident->evidence
                ->map(fn (IncidentEvidence $e) => $this->evidencePayload($e))->all(),
        ];

        if (! $full) {
            return $payload;
        }

        $incident->load(['product.consumableDetail', 'location', 'reportedBy']);

        return $payload + [
            'source_type'         => $incident->source_type,
            'source_id'           => $incident->source_id !== null ? (int) $incident->source_id : null,
            'location_id'         => (int) $incident->location_id,
            'location_name'       => $incident->location?->location_name,
            // (int) null is 0, a real product id — an `other` report has no
            // product and must serialize as null.
            'product_id'          => $incident->product_id !== null ? (int) $incident->product_id : null,
            'product_name'        => $incident->product?->product_name,
            'pieces_per_package'  => (int) ($incident->product?->pieces_per_package ?? 1),
            'package_unit'        => $incident->product?->consumableDetail?->package_unit,
            'batch_id'            => $incident->batch_id !== null ? (int) $incident->batch_id : null,
            'quantity'            => (int) $incident->quantity,
            'narrative'           => $incident->narrative,
            'expected_zone'       => $incident->expected_zone,
            'actual_zone'         => $incident->actual_zone,
            'last_known_location' => $incident->last_known_location,
            'suspected_cause'     => $incident->suspected_cause,
            'root_cause'          => $incident->root_cause,
            'return_direction'    => $incident->return_direction,
            // The document and the printed form state who the goods came from
            // or go to, and why — return_direction could say neither.
            'return_counterparty' => $incident->return_counterparty,
            'return_reason_code'  => $incident->return_reason_code,
            'return_reason'       => $incident->return_reason,
            'photo_path'          => $incident->photo_path,
            'photo_url'           => $incident->photo_path
                ? "/incident-reports/{$incident->incident_id}/photo"
                : null,
            'photo_source'        => $incident->photo_source,
            'occurred_at'         => optional($incident->occurred_at)->toIso8601String(),
            'reported_by'         => (int) $incident->reported_by,
            'reported_by_name'    => trim(($incident->reportedBy?->first_name ?? '') . ' ' . ($incident->reportedBy?->last_name ?? '')),
            'created_at'          => optional($incident->created_at)->toIso8601String(),
            // The client's document block, filled by the reporter at filing —
            // the inspection screen prints it onto their form.
            'dealer_name'         => $incident->dealer_name,
            'dealer_phone'        => $incident->dealer_phone,
            'dealer_mobile'       => $incident->dealer_mobile,
            'dealer_email'        => $incident->dealer_email,
            'branch_address'      => $incident->branch_address,
            'customer_name'       => $incident->customer_name,
            'customer_contact'    => $incident->customer_contact,
            'site_address'        => $incident->site_address,
            'purchased_at'        => optional($incident->purchased_at)->toDateString(),
            'received_at_dor'     => optional($incident->received_at_dor)->toDateString(),
        ];
    }

    /** Why a supplier return was refused, in terms the inspector can act on. */
    private function supplierGateMessage(\App\Support\SupplierWarranty $supplier): string
    {
        if ($supplier->state === WarrantyStateService::EXPIRED) {
            $who = $supplier->supplierName ?? 'the supplier';

            return "This unit's supplier warranty ran out on {$supplier->endDate}, "
                . "so it cannot be returned to {$who} as a warranty claim.";
        }

        return 'This unit cannot be traced to a receiving with a warranty term, '
            . 'so there is no supplier cover to return it under.';
    }

    private function itemPayload(IncidentItem $item, bool $withLocation = false): array
    {
        $warranty      = app(WarrantyStateService::class);
        $decision      = $warranty->evaluate($item);
        $warrantyState = $decision->customerState;

        $payload = [
            'item_id'            => (int) $item->item_id,
            'incident_id'        => (int) $item->incident_id,
            'product_id'         => (int) $item->product_id,
            'product_name'       => $item->product?->product_name,
            // Packaging, so the register can show "25 pcs · 2 Box + 5 pcs"
            // exactly as the incident list does. quantity is PIECES.
            'pieces_per_package' => (int) ($item->product?->pieces_per_package ?? 1),
            'package_unit'       => $item->product?->consumableDetail?->package_unit,
            'batch_id'           => $item->batch_id !== null ? (int) $item->batch_id : null,
            'serial_id'          => $item->serial_id !== null ? (int) $item->serial_id : null,
            'unit_role'          => $item->unit_role,
            'model_code'         => $item->model_code,
            // Falls back to the catalogued unit's serial so the client's printed
            // form shows a real number rather than a blank rule. The verbatim
            // sticker value still wins when present — when the two disagree,
            // what is physically on the box is the evidence.
            'serial_number'      => $item->serial_number ?: $item->serial?->serial_number,
            'quantity'           => (int) $item->quantity,
            // What SHOULD have been delivered, and what was ORDERED. Both are
            // recorded rather than implied: `quantity` above is what actually
            // arrived, so without expected_quantity the reader has one number
            // and no way to know it is the wrong one. Named as well as
            // identified, because a printed form showing "expected: 41" tells
            // the person holding it nothing.
            'expected_product_id'   => $item->expected_product_id !== null ? (int) $item->expected_product_id : null,
            'expected_product_name' => $item->expectedProduct?->product_name,
            'expected_quantity'     => $item->expected_quantity !== null ? (int) $item->expected_quantity : null,
            'is_inbound'         => (bool) $item->is_inbound,
            'status'             => $item->status,
            'outcome'            => $item->outcome,
            'outcome_note'       => $item->outcome_note,
            // Served, never re-derived by the client — the same discipline the
            // retired allowed_dispositions followed. The gate is enforced in
            // setOutcome(); this is what lets the screen render only the
            // buttons that will actually be accepted.
            //
            // in_warranty / expired / none are three states, not two. Expired
            // and none offer the same choices but mean different things: one
            // unit was sold and its cover ran out, the other was never
            // registered at all, and only the payload can tell them apart.
            'warranty_state'     => $warrantyState,
            // The SECOND clock. A unit in stock has no customer warranty and
            // never will until it is sold, but the supplier still stands behind
            // it — and that, not the customer's cover, is what decides whether a
            // defective one can go back. Served with its dates and supplier so
            // the screen can say WHY, not just show a colour.
            'supplier_warranty'  => $decision->supplier->toArray(),
            'allowed_outcomes'   => $decision->outcomes,
            'created_at'         => optional($item->created_at)->toIso8601String(),
        ];

        if ($withLocation) {
            $payload['incident_number'] = $item->incident?->incident_number;
            $payload['location_id']     = (int) ($item->incident?->location_id ?? 0);
            $payload['location_name']   = $item->incident?->location?->location_name;
        }

        return $payload;
    }

    private function evidencePayload(IncidentEvidence $evidence): array
    {
        return [
            'evidence_id'    => (int) $evidence->evidence_id,
            'incident_id'    => (int) $evidence->incident_id,
            'requirement'    => $evidence->requirement,
            'photo_path'     => $evidence->photo_path,
            // Evidence photos live on the private `local` disk, so this is an
            // authenticated API route, not a /storage/ URL.
            'photo_url'      => $evidence->photo_path
                ? "/incident-reports/{$evidence->incident_id}/evidence/{$evidence->evidence_id}/photo"
                : null,
            'photo_source'   => $evidence->photo_source,
            'skipped_reason' => $evidence->skipped_reason,
            'uploaded_by'    => (int) $evidence->uploaded_by,
            'created_at'     => optional($evidence->created_at)->toIso8601String(),
        ];
    }
}
