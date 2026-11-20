<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationImageStore;
use App\Services\GenerationProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

class ProcessPixelforgeWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60, 120];

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(GenerationProcessor $processor, GenerationImageStore $images): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if (
            $event === null
            || $event->status === WebhookEvent::STATUS_PROCESSED
            || $event->status === WebhookEvent::STATUS_IGNORED
        ) {
            return;
        }

        $event->increment('attempts');

        $object = $event->payload['data']['object'] ?? null;

        if (! is_array($object)) {
            $this->markIgnored($event, 'Webhook event is missing the generation object.');

            return;
        }

        try {
            $generation = $processor->process($object);
        } catch (UnexpectedValueException $e) {
            Log::error('Pixelforge webhook carried an invalid generation object.', [
                'webhook_event_id' => $event->id,
                'provider_event_id' => $event->provider_event_id,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed($event, $e->getMessage());

            return;
        }

        if ($generation === null) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);

                return;
            }

            $this->markIgnored($event, 'Generation could not be correlated.');

            return;
        }

        if ($generation->status === Generation::STATUS_SUCCEEDED && $generation->image_path === null) {
            $images->store($generation, $object['image_url'] ?? null);
        }

        $event->update([
            'status' => WebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
            'error' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null) {
            return;
        }

        $event->update([
            'status' => WebhookEvent::STATUS_FAILED,
            'error' => $exception?->getMessage() ?? 'Webhook processing failed unexpectedly.',
        ]);
    }

    private function markIgnored(WebhookEvent $event, string $reason): void
    {
        Log::warning('Pixelforge webhook event ignored.', [
            'webhook_event_id' => $event->id,
            'provider_event_id' => $event->provider_event_id,
            'reason' => $reason,
        ]);

        $event->update([
            'status' => WebhookEvent::STATUS_IGNORED,
            'error' => $reason,
            'processed_at' => now(),
        ]);
    }

    private function markFailed(WebhookEvent $event, string $error): void
    {
        $event->update([
            'status' => WebhookEvent::STATUS_FAILED,
            'error' => $error,
        ]);
    }
}
