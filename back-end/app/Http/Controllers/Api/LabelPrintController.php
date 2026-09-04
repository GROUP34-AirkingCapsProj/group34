<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\LabelPrinterException;
use App\Http\Controllers\Controller;
use App\Models\ItemSerial;
use App\Services\LabelPrinterService;
use App\Services\ZplLabelBuilder;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

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
        ]);

        $serials = ItemSerial::with('product')
            ->whereIn('serial_id', $request->input('serial_ids'))
            ->get();

        /* The client says which tags to print; it does not get to say what is
           printable. A tag is ours to reprint only when we minted it — see
           printable() — and that rule is enforced here, not in the browser. */
        $printable = $serials->filter(fn (ItemSerial $serial) => $this->printable($serial))->values();

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
               what they clicked. */
            return $this->error($e->getMessage(), 503);
        }

        return $this->success(
            ['printed' => $printable->count()],
            $printable->count() === 1 ? 'Sticker sent to the printer.' : "{$printable->count()} stickers sent to the printer.",
        );
    }

    /**
     * The same rule the tag sheet applies on screen.
     *
     * A serialized product's serial is the manufacturer's own, a tag carrying
     * a supplier barcode was printed by the supplier, and a retired tag points
     * at stock that is gone. Reprinting any of the three puts a label on the
     * wrong thing.
     */
    private function printable(ItemSerial $serial): bool
    {
        return $serial->product !== null
            && ! $serial->product->isSerialized()
            && $serial->serial_type !== 'issued'
            && $serial->supplier_barcode === null;
    }
}
