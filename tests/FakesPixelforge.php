<?php

namespace Tests;

trait FakesPixelforge
{
    /**
     * Live GET /v1/pricing payload, verified against the official docs.
     *
     * @return array<string, mixed>
     */
    protected function pricingPayload(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    /**
     * Documented POST /v1/generations 202 response shape.
     *
     * @return array<string, mixed>
     */
    protected function queuedGenerationPayload(): array
    {
        return [
            'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'object' => 'generation',
            'status' => 'queued',
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    /**
     * Documented succeeded generation object.
     *
     * Usage totals: 100 text + 1100 output + 500 reasoning tokens.
     * Provider cost 0.050750000; customer charge 0.063437500.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function succeededGenerationPayload(array $overrides = []): array
    {
        return array_replace($this->queuedGenerationPayload(), [
            'status' => 'succeeded',
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?signature=abc',
            'image_url_expires_at' => '2026-11-05T14:03:29Z',
            'usage' => [
                'input_text_tokens' => 100,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 500,
                'total_tokens' => 1700,
            ],
            'completed_at' => '2026-11-04T14:03:29Z',
        ], $overrides);
    }

    /**
     * Documented failed generation object.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function failedGenerationPayload(array $overrides = []): array
    {
        return array_replace($this->queuedGenerationPayload(), [
            'status' => 'failed',
            'usage' => null,
            'error' => [
                'code' => 'generation_failed',
                'message' => 'The model could not produce an image for the prompt.',
            ],
            'completed_at' => '2026-11-04T14:03:29Z',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    protected function webhookEvent(string $type, array $object, string $eventId = 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A'): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-04T14:03:29Z',
            'data' => ['object' => $object],
        ];
    }

    /**
     * Build a raw, correctly signed webhook request body and server headers.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, string>}
     */
    protected function signedWebhook(array $payload, ?int $timestamp = null, ?string $secret = null): array
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp ??= time();
        $secret ??= (string) config('services.pixelforge.webhook_secret');
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return [$body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'HTTP_PIXELFORGE_EVENT_ID' => $payload['id'],
            'HTTP_PIXELFORGE_EVENT_TYPE' => $payload['type'],
        ]];
    }

    protected function fakePngBytes(): string
    {
        return "\x89PNG\r\n\x1a\n".'promptedit-test-image';
    }
}
