<?php

namespace App\Providers;

use App\Support\ExactStringMatch;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Byte-exact string matching, spelled differently per database engine.
        // Registered as a macro so call sites read as ordinary query-builder
        // code; the driver rules live in ExactStringMatch.
        //
        //     DB::table('capacity_lookup')->whereExactString('capacity_value', $v)
        //
        // Eloquent builders forward unknown calls to the underlying query
        // builder, so this works from a model query too.
        QueryBuilder::macro('whereExactString', function (string $column, ?string $value) {
            /** @var QueryBuilder $this */
            return ExactStringMatch::apply($this, $column, $value);
        });

        // Apache terminates TLS on :443 and reverse-proxies /api to Laravel over
        // plain HTTP on 127.0.0.1:8081 (see apache/airking.conf), so the request
        // Laravel sees is never secure. Without this, every generated absolute
        // URL — password-reset links, signed download URLs, storage paths — comes
        // out as http:// and gets blocked as mixed content on an https:// page.
        //
        // Keyed off APP_URL rather than the incoming request: the scheme is a
        // property of how the site is published, and the request's own scheme is
        // exactly the thing the proxy hop destroys.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Login-code verification, limited by ACCOUNT first and address second.
        //
        // A plain throttle:12,1 on an unauthenticated route keys on the client
        // IP -- the one variable an attacker controls. A six-digit code is a
        // 900,000 keyspace living ten minutes, so a few hundred cheap proxies
        // buy tens of thousands of guesses and a real chance of hitting it. The
        // account-keyed limit is what actually caps that; the IP limit is a
        // secondary courtesy against a single noisy source.
        //
        // Requests with no email land in one shared bucket, which is fine: they
        // cannot pass validation anyway, and the IP limit still applies.
        RateLimiter::for('verify-otp', function (Request $request) {
            return [
                Limit::perMinute(5)->by('otp:' . Str::lower((string) $request->input('email'))),
                Limit::perMinute(12)->by('otp-ip:' . $request->ip()),
            ];
        });
    }
}
