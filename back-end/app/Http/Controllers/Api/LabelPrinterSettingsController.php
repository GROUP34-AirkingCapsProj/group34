<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\LabelPrinterException;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\SystemSetting;
use App\Services\LabelPrinterService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Which printer each location uses.
 *
 * This exists so that setting up a printer, or replacing a dead one, is a job
 * for whoever runs the warehouse rather than for whoever has file access to
 * the server. Reading is open to any signed-in user, matching the other
 * settings on the same screen; writing is admin-only.
 */
class LabelPrinterSettingsController extends Controller
{
    use ApiResponse;

    public function __construct(private LabelPrinterService $printer)
    {
    }

    /**
     * Every active location, configured or not — the panel's job is to show
     * which warehouses still need a printer, so the ones without are the
     * point. Deliberately not scoped to the caller (unlike LabelPrintController
     * ::status()): this backs an admin settings screen that must show every
     * warehouse regardless of who is looking.
     */
    public function index()
    {
        /* The whole map in one read. isConfigured() would be a settings query
           per location, and index() renders one row per location. */
        $printers = SystemSetting::labelPrinters();
        $defaults = SystemSetting::labelPrinterDefaults();

        $locations = Location::query()
            ->where('is_active', 1)
            ->orderBy('location_name')
            ->get(['location_id', 'location_name', 'location_type'])
            ->map(function (Location $location) use ($printers, $defaults) {
                $printer = $printers[$location->location_id] ?? $defaults;

                return [
                    'location_id'   => $location->location_id,
                    'location_name' => $location->location_name,
                    'location_type' => $location->location_type,
                    'printer'       => $printer,
                    'configured'    => $this->printer->isConfiguredFor($printer),
                ];
            });

        return $this->success([
            'locations' => $locations,
            /* Read-only, and shown so whoever orders labels knows what stock
               to buy. Changing it is a developer's job: the frontend hardcodes
               the same numbers for the on-screen and PDF labels. */
            'label' => [
                'width_mm'  => (int) config('labelprinter.label.width_mm'),
                'height_mm' => (int) config('labelprinter.label.height_mm'),
                'dpi'       => (int) config('labelprinter.label.dpi'),
            ],
        ]);
    }

    public function update(Request $request, int $location)
    {
        $this->findLocation($location);

        $validated = $request->validate([
            'transport' => 'present|in:tcp,share,',
            'host'      => 'nullable|string|max:255|required_if:transport,tcp',
            'port'      => 'nullable|integer|min:1|max:65535',
            'share'     => 'nullable|string|max:255|required_if:transport,share',
            /* Whole seconds: setLabelPrinter() casts with (int), and a float
               round-tripped through json_encode collapses to an int anyway. */
            'timeout'   => 'nullable|integer|min:1|max:30',
        ]);

        SystemSetting::setLabelPrinter($location, [
            'transport' => (string) ($validated['transport'] ?? ''),
            'host'      => (string) ($validated['host'] ?? ''),
            'port'      => (int) ($validated['port'] ?? 9100),
            'share'     => (string) ($validated['share'] ?? ''),
            'timeout'   => (int) ($validated['timeout'] ?? 5),
        ]);

        return $this->success([
            'printer'    => SystemSetting::labelPrinter($location),
            'configured' => $this->printer->isConfigured($location),
        ], 'Printer settings saved.');
    }

    /**
     * Always 200. An unreachable printer is an answer, not a server error —
     * the panel renders the message as information, and a 5xx would be
     * swallowed as a failed request instead of read.
     */
    public function test(int $location)
    {
        $this->findLocation($location);

        try {
            $this->printer->probe($location);
        } catch (LabelPrinterException $e) {
            return $this->success(['ok' => false, 'message' => $e->getMessage()]);
        }

        return $this->success([
            'ok'      => true,
            'message' => 'The printer answered. Print a tag to check paper and ribbon.',
        ]);
    }

    /**
     * 404 rather than a silent write: an unknown location means a stale page
     * or a hand-made request, and saving anyway would leave settings under
     * an id nothing lists, which nobody could then find or remove.
     */
    private function findLocation(int $location): Location
    {
        return Location::findOrFail($location);
    }
}
