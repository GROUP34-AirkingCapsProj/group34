<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportRun;
use App\Models\User;
use App\Reports\EmptyReportException;
use App\Reports\ReportRegistry;
use App\Reports\ReportRunner;
use App\Reports\RowCapExceededException;
use App\Reports\UnsupportedFormatException;
use App\Services\ReportRetentionService;
use App\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    use ApiResponse;

    /** Must match the disk ReportRunner writes files to. */
    private const DISK = 'local';

    /** Retention pruning off a page load — see pruneOpportunistically(). */
    private const PRUNE_MARKER = 'reports:pruned-at';
    private const PRUNE_LOCK = 'reports:prune';
    private const PRUNE_INTERVAL_MINUTES = 60;

    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
        private readonly ReportRetentionService $retention,
    ) {
    }

    /**
     * The reports this user may run, with the parameters each one declares.
     * The frontend renders its cards and filter dialogs from this alone, so a
     * card can never advertise a filter the backend does not implement.
     */
    public function catalog(Request $request)
    {
        $definitions = $this->registry->availableTo($request->user());

        $catalog = array_map(fn ($definition) => [
            'key'         => $definition->key(),
            'title'       => $definition->title(),
            'description' => $definition->description(),
            'formats'     => $definition->formats(),
            'parameters'  => array_map(fn ($parameter) => $parameter->toArray(), $definition->parameters()),
        ], $definitions);

        return $this->success($catalog, 'Report catalog retrieved successfully');
    }

    /**
     * The options behind a report's `user` filter.
     *
     * This exists because GET /users is gated on user_account_management.read,
     * which is seeded to admin alone, while report_viewing.read is seeded to
     * admin, inventory_analyst and auditor — and User Activity declares a
     * `user` filter for all three. Populating the dropdown from /users left
     * the other two roles with a silent 403 and a filter they could not use.
     *
     * It serves only what a dropdown needs. The contact details /users carries
     * stay behind the permission that guards them; being able to run a report
     * is not a reason to read everyone's email address.
     */
    public function filterUsers()
    {
        $users = User::query()
            ->select(['user_id', 'first_name', 'last_name', 'username'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return $this->success($users, 'Report filter users retrieved successfully');
    }

    public function generate(Request $request)
    {
        try {
            $validated = $request->validate([
                'report_key'   => 'required|string',
                'format'       => 'required|string|in:pdf,csv',
                'parameters'   => 'array',
            ]);

            $run = $this->runner->run(
                $validated['report_key'],
                $validated['format'],
                $validated['parameters'] ?? [],
                $request->user()
            );

            return $this->success($run->load('generatedBy'), 'Report generated successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (EmptyReportException $e) {
            return $this->error('No data matched these filters.', 422);
        } catch (RowCapExceededException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (AuthorizationException $e) {
            return $this->notFound('Report not found');
        } catch (UnsupportedFormatException $e) {
            // Caught BEFORE the \InvalidArgumentException below, which it
            // extends: the report exists, so 404 would be a lie, and its
            // message names the format the definition does not declare.
            return $this->error($e->getMessage(), 422);
        } catch (\InvalidArgumentException $e) {
            return $this->notFound('Report not found');
        }
    }

    /**
     * Recent runs, newest first. Also opportunistically prunes expired files
     * — this app runs on XAMPP with no scheduler configured, so this call is
     * what actually enforces the 90-day retention window in practice.
     */
    public function runs(Request $request)
    {
        $this->pruneOpportunistically();

        $user = $request->user();

        $runs = ReportRun::query()
            ->with('generatedBy')
            // A run the user could not download must not be listed as though
            // they could — see mayAccess(). A location-scoped holder of
            // report_viewing sees only their own; the SQL side of that is
            // here, and the report-level check is applied per row below.
            ->when(! $user->isCentralized(), fn ($q) => $q->where('generated_by', $user->user_id))
            ->orderByDesc('report_run_id')
            ->paginate(20);

        $runs->setCollection(
            $runs->getCollection()->filter(fn (ReportRun $run) => $this->mayAccess($run, $user))->values()
        );

        return $this->paginated($runs, 'Report runs retrieved successfully');
    }

    /**
     * Enforce the retention window off the back of a page load.
     *
     * This app runs on XAMPP with no scheduler, so nothing else would call
     * `reports:prune` and this really is what keeps the 90-day window. But
     * pruning is a write path — row updates and file unlinks — hanging off an
     * idempotent GET, so it is bounded twice over: at most once per interval,
     * and never by two requests at once. Before, two concurrent page loads
     * both entered the chunk loop, and the first list after a long idle
     * period unlinked every expired file while the user waited.
     *
     * The lock is taken without blocking: if another request holds it, that
     * request is already pruning and this one has nothing to wait for.
     */
    private function pruneOpportunistically(): void
    {
        if (Cache::get(self::PRUNE_MARKER) !== null) {
            return;
        }

        Cache::lock(self::PRUNE_LOCK, 120)->get(function () {
            // Re-checked inside the lock: a request can win it just as the
            // previous holder finishes, and would otherwise prune again.
            if (Cache::get(self::PRUNE_MARKER) !== null) {
                return;
            }

            $this->retention->prune();

            Cache::put(self::PRUNE_MARKER, true, now()->addMinutes(self::PRUNE_INTERVAL_MINUTES));
        });
    }

    /**
     * Whether this user may read this stored run.
     *
     * The route permission only says the user may use the reports feature at
     * all; it says nothing about one particular stored file. Two things it
     * cannot answer on its own:
     *
     * - report_viewing.read lives in an editable role-permission table, so a
     *   location-scoped role can be granted it. Such a user must not read a
     *   run of a report whose own availableTo() excludes them — User Activity
     *   returns isCentralized(), and that boundary has to survive to read time
     *   or it only ever guarded generation.
     * - A run made by a centralized user covers every location. Serving it to
     *   a location-scoped user undoes the forUser() scoping their own reports
     *   are built with, so they get only what they generated themselves.
     *
     * A run whose definition no longer exists is readable by its author alone:
     * there is no availableTo() left to ask, and guessing "allow" would make
     * deleting a definition a way to widen access to its old output.
     */
    private function mayAccess(ReportRun $run, $user): bool
    {
        if ((int) $run->generated_by === (int) $user->user_id) {
            return true;
        }

        if (! $user->isCentralized()) {
            return false;
        }

        return $this->registry->get($run->report_key)?->availableTo($user) ?? false;
    }

    public function download(Request $request, int $id)
    {
        $run = ReportRun::find($id);

        if (! $run) {
            return $this->notFound('Report run not found');
        }

        // Reported as missing rather than forbidden, so this cannot be used to
        // probe which run ids exist — same reasoning as generate()'s handling
        // of an unavailable report.
        if (! $this->mayAccess($run, $request->user())) {
            return $this->notFound('Report run not found');
        }

        if ($run->is_expired || $run->file_path === null) {
            return $this->error(
                'This report file has passed its 90-day retention window and was removed. '
                . 'Re-run the report to get current figures.',
                410
            );
        }

        if (! Storage::disk(self::DISK)->exists($run->file_path)) {
            return $this->notFound('Report file not found');
        }

        $contentType = $run->format === 'pdf' ? 'application/pdf' : 'text/csv';

        if ($request->boolean('inline') && $run->format === 'pdf') {
            return Storage::disk(self::DISK)->response($run->file_path, $run->file_name, [
                'Content-Type' => $contentType,
            ]);
        }

        return Storage::disk(self::DISK)->download($run->file_path, $run->file_name, [
            'Content-Type' => $contentType,
        ]);
    }
}
