<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\LabelPrinterException;
use App\Http\Controllers\Controller;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\SystemSetting;
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

    /**
     * Whether the UI should offer a direct-print button at all, and which
     * locations have a printer.
     *
     * Scoped the way everything else here is — literally: forUser() is the
     * same scope print() applies to the tags themselves, so a branch user is
     * told about their own location and a centralized one about all of them.
     * Closed locations are left out; a branch nobody works at does not need a
     * printer offered for it. `configured` is the summary the button is drawn
     * from — at least one printer among the locations this user can print at.
     */
    public function status(Request $request)
    {
        /* The whole map in one read. isConfigured() would be a settings query
           per location, and this endpoint is polled on page load. */
        $printers = SystemSetting::labelPrinters();
        $defaults = SystemSetting::labelPrinterDefaults();

        $locations = Location::query()
            ->forUser($request->user())
            ->where('is_active', 1)
            ->orderBy('location_name')
            ->get(['location_id', 'location_name'])
            ->map(fn (Location $location) => [
                'location_id'   => $location->location_id,
                'location_name' => $location->location_name,
                'configured'    => $this->printer->isConfiguredFor(
                    $printers[$location->location_id] ?? $defaults
                ),
            ]);

        return $this->success([
            'configured' => $locations->contains('configured', true),
            'locations'  => $locations->values(),
        ]);
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

        /* item_serial.location_id is nullable, and a null would cast to 0 and
           send staff off to set up a printer for a location the tag does not
           have. Name the real problem instead: the tag needs a location
           before it can be routed anywhere. */
        if ($printable->contains(fn (ItemSerial $serial) => $serial->location_id === null)) {
            return $this->error(
                'Some of those tags have no location recorded, so there is no printer to send them to. '
                . 'Set the location on the tag and print again.',
                422,
            );
        }

        /* Which printer this goes to is decided by where the tags are, not by
           who is printing: a label exists to be stuck on a box, and the box is
           at that location. Computed from $printable — what will actually
           print — so a tag the policy already dropped cannot make an
           otherwise-clean batch look mixed. */
        $locationIds = $printable->pluck('location_id')->unique()->values();

        if ($locationIds->count() > 1) {
            return $this->error(
                "These {$printable->count()} tags are at {$locationIds->count()} locations. "
                . 'Filter to one location and print again.',
                422,
            );
        }

        $locationId = (int) $locationIds->first();

        if (! $this->printer->isConfigured($locationId)) {
            $name = Location::find($locationId)?->location_name ?? 'this location';

            return $this->error(
                "No printer is set up for {$name}. Save the stickers as a PDF, or ask an admin to add one.",
                503,
            );
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
            $this->printer->send($zpl, $locationId);
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
