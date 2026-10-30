<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal client for the Semaphore SMS API (https://semaphore.co/docs).
 * POST /api/v4/messages  (form) apikey, number, message, sendername
 * → [ { message_id, recipient, status: "Queued"|"Pending"|"Sent"|"Failed", ... } ]
 */
class SemaphoreClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.semaphore.api_key'));
    }

    /**
     * @return array{message_id: ?string, status: string, raw: array}
     *
     * @throws RuntimeException on transport or API errors (the job retries)
     */
    public function send(string $number, string $message, ?string $senderName = null): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('services.semaphore.timeout', 10))
                ->retry(2, 500, throw: false)
                ->post(config('services.semaphore.endpoint'), [
                    'apikey' => config('services.semaphore.api_key'),
                    'number' => $number,
                    'message' => $message,
                    'sendername' => $senderName ?? config('services.semaphore.sender_name'),
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Semaphore unreachable: ' . $e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            // Semaphore returns 4xx with a JSON body describing validation errors.
            throw new RuntimeException('Semaphore rejected the message: ' . mb_substr($response->body(), 0, 400));
        }

        $first = $response->json('0') ?? $response->json() ?? [];

        return [
            'message_id' => isset($first['message_id']) ? (string) $first['message_id'] : null,
            'status' => strtolower((string) ($first['status'] ?? 'pending')),
            'raw' => is_array($first) ? $first : [],
        ];
    }

    /**
     * Normalises PH mobile numbers to 09XXXXXXXXX. Returns null for landlines
     * or anything that isn't a valid 11-digit PH mobile number.
     */
    public static function normalizeNumber(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw);

        if (str_starts_with($digits, '63')) {
            $digits = '0' . substr($digits, 2);
        } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }

        return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
    }

    /** GSM-7 friendly text (₱ and smart quotes are not in the GSM alphabet). */
    public static function sanitize(string $text, int $max = 160): string
    {
        $text = strtr($text, ['₱' => 'PHP ', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '—' => '-', '–' => '-', '…' => '...', 'ñ' => 'n', 'Ñ' => 'N']);
        $text = preg_replace('/[^\x20-\x7E\n]/', '', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 3)) . '...' : $text;
    }
}
