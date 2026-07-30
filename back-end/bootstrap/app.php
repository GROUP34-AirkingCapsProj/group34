<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // API-only app: treat every /api/* request as expecting JSON so auth and
        // validation failures always return proper JSON (not a redirect to `login`).
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

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

        // Browser-download routes authenticate from a ?token= query param (no
        // Authorization header / cookie is possible on a top-level navigation).
        // AuthenticateFromQueryToken must run BEFORE CheckPermission so the user
        // is resolved before the permission gate reads it — otherwise the gate
        // 401s and the download (PDF/CSV) never reaches the browser.
        $middleware->prependToPriorityList(
            \App\Http\Middleware\CheckPermission::class,
            \App\Http\Middleware\AuthenticateFromQueryToken::class
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Always return JSON for API routes (fixes "Response was not JSON" on 404/500)
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // Unauthenticated API requests must return a clean 401 JSON response.
        // Without this, the default handler tries to redirect to a `login` route
        // (which does not exist in this API-only app) and surfaces a 500 instead.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        // $request->validate() throws ValidationException directly (bypassing
        // ApiResponse::validationError()), so without this its default render
        // — {message, errors}, no 'success' key — is the only API shape that
        // does not match every other error response in the app.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], $e->status);
            }
        });
    })->create();