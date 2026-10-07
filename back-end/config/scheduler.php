<?php

return [

    /*
    |--------------------------------------------------------------------------
    | External scheduler trigger token
    |--------------------------------------------------------------------------
    |
    | Shared secret for POST/GET /api/scheduler/tick, which lets an external
    | cron service drive `schedule:run` on a deployment that has no cron of its
    | own. Empty (the default) disables the route entirely -- it 404s -- so no
    | environment gains a callable trigger by accident.
    |
    | Must be at least 32 characters to arm the route. Generate one with:
    |
    |     php artisan key:generate --show
    |
    | and strip the "base64:" prefix, or any other 32+ characters of real
    | entropy. Do not reuse APP_KEY.
    |
    */

    'run_token' => env('SCHEDULER_RUN_TOKEN', ''),

];
