<?php

namespace App\Services;

use App\Exceptions\LabelPrinterException;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log;

/**
 * Hands a finished ZPL job to the Zebra at one location.
 *
 * Two ways in, because a label printer is wired up one of two ways in
 * practice: on the network with an IP, or shared from the machine it is
 * plugged into. Both are a stream of bytes — the printer parses the ZPL
 * itself, so nothing here needs to understand the job it is carrying.
 *
 * Every method takes a location, because each warehouse has its own printer
 * and a label has to come out of the machine standing beside the stock. There
 * is deliberately no default and no fallback address: a printer that is not
 * the right one is worse than no printer, since nobody is watching the paper
 * come out of it.
 */
class LabelPrinterService
{
    public function isConfigured(int $locationId): bool
    {
        $printer = SystemSetting::labelPrinter($locationId);

        return in_array($printer['transport'], ['tcp', 'share'], true)
            && $this->target($printer) !== null;
    }

    /**
     * @throws LabelPrinterException when the printer is unreachable or unset.
     */
    public function send(string $payload, int $locationId): void
    {
        $printer = SystemSetting::labelPrinter($locationId);

        if (! $this->isConfigured($locationId)) {
            throw new LabelPrinterException(
                'No printer is set up for this location. Save the stickers as a PDF, '
                . 'or ask an admin to add one in System Configuration.'
            );
        }

        match ($printer['transport']) {
            'tcp'   => $this->sendOverTcp($payload, $printer),
            'share' => $this->sendToShare($payload, $printer),
        };
    }

    /**
     * Can this location's printer be reached?
     *
     * Sends nothing: no label is consumed and no stock needs to be loaded,
     * which matters on the day a printer comes out of its box. It therefore
     * cannot tell you the printer is out of paper — only that something is
     * listening where you said it would be.
     *
     * @throws LabelPrinterException with a message naming what to check.
     */
    public function probe(int $locationId): void
    {
        $printer = SystemSetting::labelPrinter($locationId);

        if (! $this->isConfigured($locationId)) {
            throw new LabelPrinterException(
                'No printer is set up for this location. Choose a connection type and save first.'
            );
        }

        if ($printer['transport'] === 'tcp') {
            $host = (string) $printer['host'];
            $port = (int) $printer['port'];

            $errorCode = 0;
            $errorMessage = '';
            $socket = @fsockopen($host, $port, $errorCode, $errorMessage, (float) $printer['timeout']);

            if ($socket === false) {
                throw new LabelPrinterException(
                    "{$host}:{$port} did not answer. Check the printer is switched on and on the same network as the server."
                );
            }

            fclose($socket);

            return;
        }

        /* Checked rather than opened: opening a printer share can start an
           empty job. This proves the path is writable, not that the printer
           is well — which is all a reachability check ever promises. */
        $share = (string) $printer['share'];
        $writable = file_exists($share) ? is_writable($share) : is_writable(dirname($share));

        if (! $writable) {
            throw new LabelPrinterException(
                "{$share} could not be written to. Check the share exists and the server has permission."
            );
        }
    }

    private function sendOverTcp(string $payload, array $printer): void
    {
        $host = (string) $printer['host'];
        $port = (int) $printer['port'];

        $errorCode = 0;
        $errorMessage = '';
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, (float) $printer['timeout']);

        if ($socket === false) {
            Log::warning('Label printer unreachable', [
                'host' => $host, 'port' => $port, 'error' => $errorMessage,
            ]);

            throw new LabelPrinterException(
                "The barcode printer at {$host}:{$port} did not answer. Check that it is on and on the network."
            );
        }

        try {
            /* A short write is not an error on a socket — it just means the
               printer's buffer filled, so the rest has to follow. */
            $remaining = $payload;
            while ($remaining !== '') {
                $written = @fwrite($socket, $remaining);
                if ($written === false || $written === 0) {
                    throw new LabelPrinterException(
                        'The barcode printer stopped accepting the job partway through. Check paper and ribbon.'
                    );
                }
                $remaining = substr($remaining, $written);
            }
        } finally {
            fclose($socket);
        }
    }

    private function sendToShare(string $payload, array $printer): void
    {
        $share = (string) $printer['share'];

        if (@file_put_contents($share, $payload) === false) {
            Log::warning('Label printer share not writable', ['share' => $share]);

            throw new LabelPrinterException(
                "The barcode printer at {$share} could not be written to. Check that the share exists and the server can reach it."
            );
        }
    }

    /** The address for the configured transport, or null when it is missing. */
    private function target(array $printer): ?string
    {
        $target = match ($printer['transport']) {
            'tcp'   => $printer['host'],
            'share' => $printer['share'],
            default => null,
        };

        return $target === null || $target === '' ? null : (string) $target;
    }
}
