<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Drives Laravel's scheduler from an external cron service.
 *
 * The deployed API has no cron: `schedule:run` is never invoked, so
 * analytics:restock, archive:auto, warranties:expire, archive:cleanup,
 * backup:run and ledger:drift-check have never executed in production
 * (docs/DEPLOY-RENDER.md recorded this). A Render Cron Job is the normal answer;
 * this endpoint exists so a free external cron (cron-job.org and friends) can do
 * the same job without a second paid service.
 *
 * It is one minute of Laravel's own scheduler, nothing more: every decision
 * about what is due still belongs to routes/console.php. This does not get to
 * choose which commands run, and deliberately exposes no way to name one --
 * otherwise it would be a remote command-execution endpoint wearing a hat.
 *
 * Security, in the order it matters:
 *
 *   - Disabled unless SCHEDULER_RUN_TOKEN is set and at least 32 characters.
 *     Fails CLOSED: an unset token means the route 404s, so forgetting to
 *     configure it cannot leave a world-callable trigger behind.
 *   - 404, not 401/403, on a bad token. A 403 confirms the endpoint exists and
 *     invites guessing; a 404 is indistinguishable from a typo'd URL.
 *   - hash_equals for the comparison, so the token cannot be recovered a byte
 *     at a time from response timing.
 *   - Rate limited in the route definition. Legitimate use is one call a minute.
 *   - The token is never written to a log, a response body, or an exception.
 *
 * Worst case if the token leaks: an attacker can make the scheduler tick more
 * often than once a minute. Every task is `withoutOverlapping()` and gated on
 * its own due-time, so extra ticks are no-ops rather than repeated work. That is
 * the reason this is an acceptable shape at all.
 */
class SchedulerTickController extends Controller
{
    /**
     * Minimum token length. 32 characters of `php artisan key:generate --show`
     * style entropy is far past brute-forcing at the rate limit above, and the
     * floor exists to stop a placeholder like "changeme" from arming the route.
     */
    private const MIN_TOKEN_LENGTH = 32;

    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('scheduler.run_token', '');

        if (strlen($expected) < self::MIN_TOKEN_LENGTH) {
            // Not an error worth alerting on -- this is the default state for
            // every environment that does not use an external cron.
            throw new NotFoundHttpException();
        }

        if (! hash_equals($expected, $this->presentedToken($request))) {
            // Logged without the presented value: recording attacker-supplied
            // input verbatim is how secrets end up in log aggregators.
            Log::warning('scheduler.tick rejected: bad token', [
                'ip' => $request->ip(),
            ]);

            throw new NotFoundHttpException();
        }

        $started = microtime(true);

        // schedule:run resolves what is due itself. Output is captured rather
        // than returned: it names the commands that ran, which is operational
        // detail an unauthenticated-ish endpoint has no reason to hand back.
        $exitCode = Artisan::call('schedule:run');
        $output = trim(Artisan::output());

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        Log::info('scheduler.tick ran', [
            'exit_code'   => $exitCode,
            'duration_ms' => $durationMs,
        ]);

        return response()->json([
            'ran'         => true,
            'exit_code'   => $exitCode,
            'duration_ms' => $durationMs,
            // A count, not the text. Enough for a cron dashboard to show that
            // something happened without describing the system to a caller.
            'task_lines'  => $output === '' ? 0 : substr_count($output, "\n") + 1,
        ]);
    }

    /**
     * Header first, query string second.
     *
     * The query form exists because not every free cron service can set custom
     * headers, but it is the worse option: URLs are written to access logs,
     * proxy logs and browser history in a way headers are not. The docs say so.
     */
    private function presentedToken(Request $request): string
    {
        $header = $request->header('X-Scheduler-Token');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $query = $request->query('token');

        return is_string($query) ? $query : '';
    }
}
