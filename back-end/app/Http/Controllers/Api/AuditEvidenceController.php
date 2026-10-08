<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerDriftFinding;
use App\Services\Ledger\EvidenceResolver;
use App\Services\Ledger\LedgerFilter;
use App\Services\Ledger\LedgerQueryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The Audit & Evidence Explorer's read surface.
 *
 * Every list is filtered, grouped and paginated server-side. The page this replaces
 * fetched six sources in full and stitched them in the browser, which is how its
 * numbers drifted from reality — so nothing here hands a whole table to the client.
 */
class AuditEvidenceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private LedgerQueryService $ledger,
        private EvidenceResolver $evidence
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->withFilter($request, fn (LedgerFilter $f) => $this->success($this->ledger->summary($f)));
    }

    public function breakdown(Request $request): JsonResponse
    {
        return $this->withFilter($request, function (LedgerFilter $f) use ($request) {
            return $this->success(
                $this->ledger->breakdown($f, (string) $request->input('dimension', 'movement_type'))
            );
        });
    }

    public function movements(Request $request): JsonResponse
    {
        return $this->withFilter($request, function (LedgerFilter $f) use ($request) {
            $page = $this->ledger->movements($f, (int) $request->input('per_page', 50));

            return response()->json([
                'success'    => true,
                'data'       => $page->items(),
                'pagination' => [
                    'total'        => $page->total(),
                    'per_page'     => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                ],
            ]);
        });
    }

    public function reconciliation(Request $request): JsonResponse
    {
        return $this->withFilter($request, fn (LedgerFilter $f) => $this->success($this->ledger->reconciliation($f)));
    }

    public function event(string $correlationId): JsonResponse
    {
        return $this->success($this->evidence->forCorrelation($correlationId));
    }

    public function serialLifecycle(string $serial): JsonResponse
    {
        return $this->success($this->evidence->lifecycleForSerial($serial));
    }

    public function batchLifecycle(int $batchId): JsonResponse
    {
        return $this->success($this->evidence->lifecycleForBatch($batchId));
    }

    public function exceptions(Request $request): JsonResponse
    {
        $query = LedgerDriftFinding::query()->with('resolver:user_id,first_name,last_name,username');

        if (! $request->boolean('include_resolved')) {
            $query->whereNull('resolved_at');
        }

        $findings = $query->orderByDesc('detected_at')->limit(200)->get()->map(fn ($f) => [
            'finding_id'      => $f->finding_id,
            'check_type'      => $f->check_type,
            'source_type'     => $f->source_type,
            'period_start'    => $f->period_start?->toDateString(),
            'period_end'      => $f->period_end?->toDateString(),
            'scope'           => $f->scope,
            'expected'        => $f->expected !== null ? (float) $f->expected : null,
            'actual'          => $f->actual !== null ? (float) $f->actual : null,
            'variance'        => $f->variance !== null ? (float) $f->variance : null,
            'detail'          => $f->detail,
            'detected_at'     => $f->detected_at?->toIso8601String(),
            'resolved_at'     => $f->resolved_at?->toIso8601String(),
            'resolved_by'     => $f->resolver
                ? (trim(($f->resolver->first_name ?? '') . ' ' . ($f->resolver->last_name ?? '')) ?: $f->resolver->username)
                : null,
            'resolution_note' => $f->resolution_note,
            'is_open'         => $f->isOpen(),
        ]);

        return $this->success($findings);
    }

    public function resolveException(Request $request, int $findingId): JsonResponse
    {
        // A note is required, not optional: a finding closed with no explanation
        // leaves the discrepancy unexplained while looking dealt with.
        $validated = $request->validate([
            'resolution_note' => 'required|string|min:3|max:500',
        ]);

        $finding = LedgerDriftFinding::findOrFail($findingId);

        if (! $finding->isOpen()) {
            return $this->error('That finding is already resolved.', 409);
        }

        $finding->update([
            'resolved_at'     => Carbon::now(),
            'resolved_by'     => $request->user()?->user_id,
            'resolution_note' => $validated['resolution_note'],
        ]);

        return $this->success(['finding_id' => $finding->finding_id], 'Finding resolved.');
    }

    /**
     * Build the filter once, and turn its refusals into a 422 the UI can show.
     *
     * A period too wide to total is a message, never a silently truncated answer —
     * a partial figure that looks complete is the exact failure this feature exists
     * to end.
     */
    private function withFilter(Request $request, callable $then): JsonResponse
    {
        try {
            $filter = LedgerFilter::fromArray($request->all());
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        try {
            return $then($filter);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
