<?php

namespace App\Services;

use Illuminate\Http\Request;

class PixelforgeWebhookVerifier
{
    private const int MAX_TIMESTAMP_AGE_SECONDS = 300;

    /**
     * Verify the Pixelforge-Signature header against the raw request body.
     */
    public function verify(Request $request, string $payload): bool
    {
        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '') {
            return false;
        }

        $header = (string) $request->header('Pixelforge-Signature', '');

        if ($header === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $timestamp = $this->timestamp($parts['t'] ?? '');
        $signature = $parts['v1'] ?? '';

        if ($timestamp === null || $signature === '') {
            return false;
        }

        if (abs(time() - $timestamp) > self::MAX_TIMESTAMP_AGE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return hash_equals($expected, $signature);
    }

    private function timestamp(string $value): ?int
    {
        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
