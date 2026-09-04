<?php

namespace App\Services;

use App\Exceptions\LabelPrinterException;
use Illuminate\Support\Facades\Log;

/**
 * Hands a finished ZPL job to the Zebra.
 *
 * Two ways in, because a label printer is wired up one of two ways in
 * practice: on the network with an IP, or shared from the machine it is
 * plugged into. Both are a stream of bytes — the printer parses the ZPL
 * itself, so nothing here needs to understand the job it is carrying.
 */
class LabelPrinterService
{
    public function isConfigured(): bool
    {
        return in_array($this->transport(), ['tcp', 'share'], true)
            && $this->target() !== null;
    }

    /**
     * @throws LabelPrinterException when the printer is unreachable or unset.
     */
    public function send(string $payload): void
    {
        if (! $this->isConfigured()) {
            throw new LabelPrinterException(
                'No barcode printer is set up on the server. Save the stickers as a PDF, '
                . 'or ask IT to set LABEL_PRINTER_TRANSPORT and the printer address.'
            );
        }

        match ($this->transport()) {
            'tcp'   => $this->sendOverTcp($payload),
            'share' => $this->sendToShare($payload),
        };
    }

    private function sendOverTcp(string $payload): void
    {
        $host = (string) config('labelprinter.host');
        $port = (int) config('labelprinter.port');

        $errorCode = 0;
        $errorMessage = '';
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, (float) config('labelprinter.timeout'));

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

    private function sendToShare(string $payload): void
    {
        $share = (string) config('labelprinter.share');

        if (@file_put_contents($share, $payload) === false) {
            Log::warning('Label printer share not writable', ['share' => $share]);

            throw new LabelPrinterException(
                "The barcode printer at {$share} could not be written to. Check that the share exists and the server can reach it."
            );
        }
    }

    private function transport(): ?string
    {
        $transport = config('labelprinter.transport');

        return $transport === null || $transport === '' ? null : (string) $transport;
    }

    /** The address for the configured transport, or null when it is missing. */
    private function target(): ?string
    {
        $target = match ($this->transport()) {
            'tcp'   => config('labelprinter.host'),
            'share' => config('labelprinter.share'),
            default => null,
        };

        return $target === null || $target === '' ? null : (string) $target;
    }
}
