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
    /**
     * Reads the setting itself. Prefer isConfiguredFor() when you already
     * hold the entry — SystemSetting is an uncached table read, so asking
     * this once per location in a loop is a query per location.
     */
    public function isConfigured(int $locationId): bool
    {
        return $this->isConfiguredFor(SystemSetting::labelPrinter($locationId));
    }

    /**
     * The predicate on its own, for a caller that has already loaded the
     * entry — from labelPrinters() when listing every location, or from the
     * single load a send() or probe() has to do anyway.
     *
     * @param array{transport:string,host:string,port:int,share:string,timeout:int} $printer
     *   One location's connection exactly as SystemSetting::labelPrinter()
     *   returns it: always fully populated, transport already whitelisted to
     *   'tcp'/'share'/'', port and timeout already ints. This is the whole
     *   contract between that model and this service — nothing here re-casts
     *   or re-validates it.
     */
    public function isConfiguredFor(array $printer): bool
    {
        return in_array($printer['transport'], ['tcp', 'share'], true)
            && $this->target($printer) !== null;
    }

    /**
     * @throws LabelPrinterException when the printer is unreachable or unset.
     */
    public function send(string $payload, int $locationId): void
    {
        $printer = SystemSetting::labelPrinter($locationId);

        if (! $this->isConfiguredFor($printer)) {
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

        if (! $this->isConfiguredFor($printer)) {
            throw new LabelPrinterException(
                'No printer is set up for this location. Choose a connection type and save first.'
            );
        }

        match ($printer['transport']) {
            'tcp'   => $this->probeTcp($printer),
            'share' => $this->probeShare($printer),
        };
    }

    /** @param array{transport:string,host:string,port:int,share:string,timeout:int} $printer */
    private function probeTcp(array $printer): void
    {
        $host = (string) $printer['host'];
        $port = (int) $printer['port'];

        $errorCode = 0;
        $errorMessage = '';
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, (float) $printer['timeout']);

        if ($socket === false) {
            /* The socket's own reason, verbatim. On setup day it is the most
               useful fact available: "Connection refused" means something
               answered and said no — wrong port, or raw printing disabled —
               while a timeout means nothing answered at all, which is a
               wrong address, a wrong subnet or a firewall. */
            throw new LabelPrinterException(
                "{$host}:{$port} did not answer ({$errorMessage}). Check the printer is switched on and on the same network as the server."
            );
        }

        fclose($socket);
    }

    /**
     * Checked rather than opened: opening a printer share can start an empty
     * job, and a reachability check must not consume a label.
     *
     * That leaves this able to inspect only a share that names something on a
     * filesystem it can stat — a local port, a spool file, a mapped drive. A
     * UNC path like \\THIS-PC\Zebra, the usual way a label printer is shared,
     * is not inspectable: file_exists() is false for it and its "parent" is a
     * host rather than a directory. So this reports a share broken only where
     * it can genuinely see that it is, and stays quiet everywhere else rather
     * than failing a healthy printer. The only real test of a share is
     * printing one tag.
     *
     * @param array{transport:string,host:string,port:int,share:string,timeout:int} $printer
     */
    private function probeShare(array $printer): void
    {
        $share = (string) $printer['share'];

        if (file_exists($share)) {
            if (! is_writable($share)) {
                throw new LabelPrinterException(
                    "{$share} exists but could not be written to. Check the server has permission to write there."
                );
            }

            return;
        }

        $parent = dirname($share);

        if (is_dir($parent) && ! is_writable($parent)) {
            throw new LabelPrinterException(
                "{$share} could not be written to: {$parent} is not writable by the server."
            );
        }
    }

    /** @param array{transport:string,host:string,port:int,share:string,timeout:int} $printer */
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

    /** @param array{transport:string,host:string,port:int,share:string,timeout:int} $printer */
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

    /**
     * The address for the configured transport, or null when it is missing.
     *
     * @param array{transport:string,host:string,port:int,share:string,timeout:int} $printer
     */
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
