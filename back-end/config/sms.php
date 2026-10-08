<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default SMS Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "semaphore", "twilio", "vonage", "log"
    | Default is Semaphore (Philippines). Use "log" for local dry-runs.
    |
    */

    'driver' => env('SMS_DRIVER', 'semaphore'),

    'semaphore' => [
        'api_key' => env('SEMAPHORE_API_KEY'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME', 'SEMAPHORE'),
        'api_url' => env('SEMAPHORE_API_URL', 'https://api.semaphore.co/api/v4/messages'),
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],

    'vonage' => [
        'api_key' => env('VONAGE_API_KEY'),
        'api_secret' => env('VONAGE_API_SECRET'),
        'from' => env('VONAGE_FROM', 'AirKing'),
    ],

];
