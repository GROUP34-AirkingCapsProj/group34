<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Provider-agnostic SMS sender.
 *
 * Switch drivers via SMS_DRIVER in .env:
 *   semaphore (default for PH) | twilio | vonage | log
 */
class SmsService
{
    /**
     * Send an SMS to $to. Throws RuntimeException on failure.
     */
    public function send(string $to, string $message): void
    {
        $driver = strtolower((string) config('sms.driver', 'semaphore'));

        match ($driver) {
            'twilio' => $this->viaTwilio($to, $message),
            'vonage' => $this->viaVonage($to, $message),
            'log' => $this->viaLog($to, $message),
            default => $this->viaSemaphore($to, $message),
        };
    }

    private function viaLog(string $to, string $message): void
    {
        Log::info('sms_service_log_driver', [
            'to' => $to,
            'message' => $message,
        ]);
    }

    private function viaSemaphore(string $to, string $message): void
    {
        $apiKey = (string) config('sms.semaphore.api_key');
        $sender = (string) config('sms.semaphore.sender_name', 'SEMAPHORE');
        $url = (string) config('sms.semaphore.api_url', 'https://api.semaphore.co/api/v4/messages');

        if ($apiKey === '') {
            throw new RuntimeException('Semaphore SMS is not configured. Set SEMAPHORE_API_KEY in .env.');
        }

        $number = $this->toPhilippineLocal($to);

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->post($url, [
                'apikey' => $apiKey,
                'number' => $number,
                'message' => $message,
                'sendername' => $sender,
            ]);

        if (! $response->successful()) {
            Log::error('sms_semaphore_http_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'to' => $number,
            ]);
            throw new RuntimeException('Semaphore rejected the SMS request.');
        }

        $payload = $response->json();

        // Semaphore may return { message: "..." } on soft failures.
        if (is_array($payload) && isset($payload['message']) && ! isset($payload[0])) {
            Log::error('sms_semaphore_api_error', ['payload' => $payload, 'to' => $number]);
            throw new RuntimeException((string) $payload['message']);
        }
    }

    private function viaTwilio(string $to, string $message): void
    {
        $sid = (string) config('sms.twilio.account_sid');
        $token = (string) config('sms.twilio.auth_token');
        $from = (string) config('sms.twilio.from');

        if ($sid === '' || $token === '' || $from === '') {
            throw new RuntimeException('Twilio SMS is not configured. Set TWILIO_* in .env.');
        }

        $e164 = $this->toE164($to);

        $response = Http::withBasicAuth($sid, $token)
            ->asForm()
            ->timeout(20)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'From' => $from,
                'To' => $e164,
                'Body' => $message,
            ]);

        if (! $response->successful()) {
            Log::error('sms_twilio_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Failed to send SMS via Twilio.');
        }
    }

    private function viaVonage(string $to, string $message): void
    {
        $key = (string) config('sms.vonage.api_key');
        $secret = (string) config('sms.vonage.api_secret');
        $from = (string) config('sms.vonage.from', 'AirKing');

        if ($key === '' || $secret === '') {
            throw new RuntimeException('Vonage SMS is not configured. Set VONAGE_* in .env.');
        }

        $e164 = ltrim($this->toE164($to), '+');

        $response = Http::asForm()
            ->timeout(20)
            ->post('https://rest.nexmo.com/sms/json', [
                'api_key' => $key,
                'api_secret' => $secret,
                'to' => $e164,
                'from' => $from,
                'text' => $message,
            ]);

        if (! $response->successful()) {
            Log::error('sms_vonage_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Failed to send SMS via Vonage.');
        }

        $status = data_get($response->json(), 'messages.0.status');
        if ($status !== null && (string) $status !== '0') {
            Log::error('sms_vonage_rejected', ['payload' => $response->json()]);
            throw new RuntimeException('Vonage rejected the SMS message.');
        }
    }

    /** PH local format for Semaphore: 09xxxxxxxxx */
    private function toPhilippineLocal(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '63') && strlen($digits) >= 12) {
            return '0' . substr($digits, 2);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return $digits;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0' . $digits;
        }

        return $digits;
    }

    /** E.164 for Twilio/Vonage: +639xxxxxxxxx */
    private function toE164(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+63' . substr($digits, 1);
        }

        if (str_starts_with($digits, '63')) {
            return '+' . $digits;
        }

        if (str_starts_with($phone, '+')) {
            return '+' . $digits;
        }

        return $digits !== '' ? '+' . $digits : $phone;
    }
}
