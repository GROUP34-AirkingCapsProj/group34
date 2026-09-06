<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\LabelPrinterException;
use App\Http\Controllers\Controller;
use App\Models\ItemSerial;
use App\Services\LabelPrinterService;
use App\Services\TagPrintPolicy;
use App\Services\ZplLabelBuilder;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sends barcode stickers straight to the Zebra.
 *
 * The browser can build the same stickers as a PDF, but it cannot choose a
 * printer or skip the print dialog — no browser can. Printing without a dialog
 * means the server holds the connection to the printer, which is what this is.
 */
class LabelPrintController extends Controller
{
    use ApiResponse;

    public function __construct(
        private LabelPrinterService $printer,
        private TagPrintPolicy $policy,
    ) {
    }

    /** Whether the UI should offer a direct-print button at all. */
    public function status()
    {
        return $this->success(['configured' => $this->printer->isConfigured()]);
    }

    public function print(Request $request)
    {
        $request->validate([
            'serial_ids'   => 'required|array|min:1|max:200',
            'serial_ids.*' => 'integer',
            'mode'         => 'nullable|in:bulk,single',
        ]);

        /* Defaulting to bulk keeps every existing caller — the per-product tag
           sheet — on exactly the rule it had before modes existed. */
        $mode = (string) $request->input('mode', 'bulk');
        $ids  = $request->input('serial_ids');

        if ($mode === 'single' && count($ids) !== 1) {
            return $this->error('A single reprint sends exactly one label.', 422);
        }

        /* forUser() is the same location scope Inventory, Receiving and
           Issuance already apply. The client sends serial ids, so without it
           a branch-scoped user could reprint labels for another branch's
           stock simply by guessing ids. Centralized roles are unaffected. */
        $serials = ItemSerial::with(['product.applianceDetail', 'product.consumableDetail'])
            ->forUser($request->user())
            ->whereIn('serial_id', $ids)
            ->get();

        /* The client says which tags to print; it does not get to say what is
           printable. TagPrintPolicy owns that rule and it is enforced here,
           not in the browser. */
        $printable = $serials
            ->filter(fn (ItemSerial $serial) => $this->policy->printable($serial, $mode))
            ->values();

        if ($printable->isEmpty()) {
            return $this->error('None of those tags can be reprinted.', 422);
        }

        $builder = new ZplLabelBuilder(
            widthMm: (int) config('labelprinter.label.width_mm'),
            heightMm: (int) config('labelprinter.label.height_mm'),
            dpi: (int) config('labelprinter.label.dpi'),
        );

        $zpl = $builder->build($printable->map(fn (ItemSerial $serial) => [
            'value' => $serial->serial_number,
            'name'  => $serial->product?->product_name,
        ])->all());

        try {
            $this->printer->send($zpl);
        } catch (LabelPrinterException $e) {
            /* 503: the request was fine, the printer is not. The UI tells the
               user to use the PDF instead, so this must not read as a bug in
               what they clicked. Nothing is stamped — a job that never left
               must not leave a trail saying it did. */
            return $this->error($e->getMessage(), 503);
        }

        $this->stampPrinted($printable->pluck('serial_id')->all(), $request->user()?->user_id);

        return $this->success(
            ['printed' => $printable->count()],
            $printable->count() === 1 ? 'Sticker sent to the printer.' : "{$printable->count()} stickers sent to the printer.",
        );
    }

    /**
     * Record that these tags went to the printer.
     *
     * Called only after send() returns without throwing, so a dead printer
     * leaves no trace. first_printed_at is written in its own statement
     * guarded by whereNull: the first send owns that date permanently, and a
     * reprint must not overwrite the history it is evidence of.
     */
    private function stampPrinted(array $serialIds, ?int $userId): void
    {
        /* A query-builder update, not a loop of model saves. ItemSerial uses
           the Auditable trait, so stamping 200 tags through Eloquent would
           write 200 audit_trail rows for one press of one button. This form
           bypasses model events deliberately — the print history columns are
           themselves the record of what happened, and they are on the row
           being written. */
        $now = now();

        DB::transaction(function () use ($serialIds, $userId, $now) {
            ItemSerial::whereIn('serial_id', $serialIds)->update([
                'last_printed_at' => $now,
                'last_printed_by' => $userId,
                'print_count'     => DB::raw('print_count + 1'),
            ]);

            ItemSerial::whereIn('serial_id', $serialIds)
                ->whereNull('first_printed_at')
                ->update(['first_printed_at' => $now]);
        });
    }
}
