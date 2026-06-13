<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register your custom middleware aliases
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'branch' => \App\Http\Middleware\CheckBranch::class,
            'log.activity' => \App\Http\Middleware\LogActivity::class,
            'auditor.readonly' => \App\Http\Middleware\AuditorReadOnly::class,
            'location.scope' => \App\Http\Middleware\EnsureAssignedLocationAccess::class,
            'extend.sanctum.session' => \App\Http\Middleware\ExtendSanctumTokenExpiration::class,
            'auth.query.token'       => \App\Http\Middleware\AuthenticateFromQueryToken::class,
            'permission'             => \App\Http\Middleware\CheckPermission::class,
        ]);

        // Run authorization (role/auditor) BEFORE route-model binding, so a
        // denied user gets a 403 from the gate rather than a 404 from binding a missing
        // model — and we never resolve resources for unauthorized requests.
        $substitute = \Illuminate\Routing\Middleware\SubstituteBindings::class;
        $middleware->prependToPriorityList($substitute, \App\Http\Middleware\AuditorReadOnly::class);
        $middleware->prependToPriorityList($substitute, \App\Http\Middleware\CheckRole::class);
        $middleware->prependToPriorityList($substitute, \App\Http\Middleware\CheckPermission::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Always return JSON for API routes (fixes "Response was not JSON" on 404/500)
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();