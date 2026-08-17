<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportRun;
use App\Reports\EmptyReportException;
use App\Reports\ReportRegistry;
use App\Reports\ReportRunner;
use App\Reports\RowCapExceededException;
use App\Services\ReportRetentionService;
use App\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    use ApiResponse;

    /** Must match the disk ReportRunner writes files to. */
    private const DISK = 'local';

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
        $this->retention->prune();

        $runs = ReportRun::query()
            ->with('generatedBy')
            ->orderByDesc('report_run_id')
            ->paginate(20);

        return $this->paginated($runs, 'Report runs retrieved successfully');
    }

    public function download(Request $request, int $id)
    {
        $run = ReportRun::find($id);

        if (! $run) {
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
