<?php

namespace App\Jobs;

use App\Exceptions\PixelforgeApiException;
use App\Models\Generation;
use App\Services\PixelforgeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubmitGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const int MAX_ATTEMPTS = 5;

    /** @var list<int> */
    private const array BACKOFF_SECONDS = [5, 15, 30, 60];

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 60;

    public function __construct(public readonly int $generationId) {}

    public function handle(PixelforgeClient $client): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation === null || $generation->provider_generation_id !== null || $generation->isTerminal()) {
            return;
        }

        try {
            $data = $client->createGeneration(
                $this->payload($generation),
                $generation->idempotency_key,
            );
        } catch (PixelforgeApiException $e) {
            if ($e->retryable && $this->attempts() < self::MAX_ATTEMPTS) {
                $delay = $this->retryDelay($e->retryAfter);

                Log::warning('Pixelforge generation submission failed; scheduling retry.', [
                    'generation_id' => $generation->id,
                    'idempotency_key' => $generation->idempotency_key,
                    'error_code' => $e->errorCode,
                    'status_code' => $e->statusCode,
                    'uncertain' => $e->uncertain,
                    'attempt' => $this->attempts(),
                    'retry_delay_seconds' => $delay,
                ]);

                $this->release($delay);

                return;
            }

            Log::error('Pixelforge generation submission failed permanently.', [
                'generation_id' => $generation->id,
                'idempotency_key' => $generation->idempotency_key,
                'error_code' => $e->errorCode,
                'status_code' => $e->statusCode,
                'uncertain' => $e->uncertain,
                'attempt' => $this->attempts(),
                'message' => $e->getMessage(),
            ]);

            $errorCode = $e->errorCode ?? ($e->uncertain ? 'submission_uncertain' : 'provider_error');
            $this->markFailed($errorCode, $e->getMessage());

            return;
        }

        $this->persistProviderResponse($data);
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed(
            'internal_error',
            $exception?->getMessage() ?? 'Generation submission failed unexpectedly.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Generation $generation): array
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'metadata' => ['generation_id' => (string) $generation->id],
        ];

        if ($generation->reference_images !== null && $generation->reference_images !== []) {
            $payload['reference_images'] = array_values($generation->reference_images);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistProviderResponse(array $data): void
    {
        DB::transaction(function () use ($data): void {
            $locked = Generation::whereKey($this->generationId)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $updates = [];

            if ($locked->provider_generation_id === null) {
                $updates['provider_generation_id'] = $data['id'];
            }

            if ($locked->rates === null && isset($data['rates']) && is_array($data['rates'])) {
                $updates['rates'] = $data['rates'];
            }

            if ($updates !== []) {
                $locked->update($updates);
            }
        });
    }

    private function markFailed(string $errorCode, string $message): void
    {
        DB::transaction(function () use ($errorCode, $message): void {
            $locked = Generation::whereKey($this->generationId)->lockForUpdate()->first();

            // Never clobber a terminal generation or one already correlated by an early webhook.
            if ($locked === null || $locked->isTerminal() || $locked->provider_generation_id !== null) {
                return;
            }

            $locked->update([
                'status' => Generation::STATUS_FAILED,
                'error_code' => $errorCode,
                'error_message' => $message,
                'completed_at' => now(),
            ]);
        });
    }

    private function retryDelay(?int $retryAfter): int
    {
        $backoff = self::BACKOFF_SECONDS[$this->attempts() - 1] ?? 60;

        return max($backoff, $retryAfter ?? 0);
    }
}
