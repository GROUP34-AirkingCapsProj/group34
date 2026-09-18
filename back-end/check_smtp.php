<?php

/**
 * One-off SMTP check for Brevo (run from back-end):
 *   php artisan tinker --execute="require 'check_smtp.php';"
 * Or: php check_smtp.php  (bootstraps Laravel below)
 */

use Illuminate\Support\Facades\Mail;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$to = $argv[1] ?? env('MAIL_FROM_ADDRESS');
$host = config('mail.mailers.smtp.host');
$user = config('mail.mailers.smtp.username');
$from = config('mail.from.address');

echo "MAIL_MAILER=".config('mail.default').PHP_EOL;
echo "SMTP host={$host} user={$user} from={$from}".PHP_EOL;
echo "Sending test to {$to} ...".PHP_EOL;

try {
    Mail::raw('Airking SMTP test — if you received this, Brevo login/key works.', function ($message) use ($to) {
        $message->to($to)->subject('Airking SMTP test');
    });
    echo "OK: message accepted by mailer.".PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo 'FAIL: '.$e->getMessage().PHP_EOL;
    if (str_contains($e->getMessage(), '535') || str_contains(strtolower($e->getMessage()), 'authentication')) {
        echo PHP_EOL.'Fix: In Brevo → SMTP & API → create a NEW SMTP key.'.PHP_EOL;
        echo 'Update MAIL_USERNAME (SMTP login) and MAIL_PASSWORD (SMTP key) in .env,'.PHP_EOL;
        echo 'then run: php artisan config:clear'.PHP_EOL;
        echo 'Also verify MAIL_FROM_ADDRESS under Brevo → Senders & IPs.'.PHP_EOL;
    }
    exit(1);
}
