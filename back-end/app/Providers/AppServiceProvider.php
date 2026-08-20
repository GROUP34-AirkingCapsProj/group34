<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
    }
}
