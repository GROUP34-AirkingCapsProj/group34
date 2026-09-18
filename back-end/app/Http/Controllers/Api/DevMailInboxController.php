<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Local-dev convenience only: MAIL_MAILER=log writes outgoing mail (OTP codes,
 * password resets) into storage/logs/laravel.log instead of an inbox. Reading
 * that log by hand to find a 6-digit code is tedious, so this exposes the
 * latest one for a given address as plain text.
 *
 * Registered only when app()->environment('local') (see routes/api.php) and
 * additionally refuses to run at all unless MAIL_MAILER is still "log" here,
 * so it cannot accidentally leak a real code if someone flips SMTP on without
 * removing the route.
 */
class DevMailInboxController extends Controller
{
    public function lastOtp(Request $request)
    {
        if (!app()->environment('local') || config('mail.default') !== 'log') {
            abort(404);
        }

        $email = $request->query('email');
        if (!$email) {
            return response('Pass ?email=you@example.com', Response::HTTP_BAD_REQUEST);
        }

        $path = storage_path('logs/laravel.log');
        if (!is_file($path)) {
            return response('No log file yet.', Response::HTTP_NOT_FOUND);
        }

        // Tail the last ~2MB rather than parsing a potentially huge log file.
        $size = filesize($path);
        $chunk = 2 * 1024 * 1024;
        $handle = fopen($path, 'r');
        fseek($handle, max(0, $size - $chunk));
        $tail = stream_get_contents($handle);
        fclose($handle);
        $tail = str_replace("\r\n", "\n", $tail);

        // Each mail dump starts with a "[timestamp] local.DEBUG: From:" line.
        // Split into blocks and scan from the newest backwards for one addressed
        // to the requested email.
        $blocks = preg_split('/(?=\[\d{4}-\d{2}-\d{2} [\d:]+\] \w+\.DEBUG: From:)/', $tail);

        for ($i = count($blocks) - 1; $i >= 0; $i--) {
            $block = $blocks[$i];

            if (!preg_match('/^\[([^\]]+)\]/', $block, $tsMatch)) {
                continue;
            }
            if (!preg_match('/^To:\s*(.+)$/m', $block, $toMatch)) {
                continue;
            }
            if (trim($toMatch[1]) !== $email) {
                continue;
            }
            if (!preg_match('/^Subject:\s*(.+)$/m', $block, $subjectMatch)) {
                continue;
            }
            // The template renders the code as its own line, e.g. "    631047".
            if (!preg_match('/^[ \t]*(\d{6})[ \t]*$/m', $block, $codeMatch)) {
                continue;
            }

            return response(
                "Sent: {$tsMatch[1]}\n"
                . "Subject: " . trim($subjectMatch[1]) . "\n"
                . "Code: {$codeMatch[1]}",
                Response::HTTP_OK
            )->header('Content-Type', 'text/plain');
        }

        return response("No recent code found for {$email}.", Response::HTTP_NOT_FOUND);
    }
}
