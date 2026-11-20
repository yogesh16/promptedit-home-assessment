<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\FakesPixelforge;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
            'services.pixelforge.api_key' => 'pf_test_key',
            'services.pixelforge.webhook_secret' => 'whsec_test_secret',
        ]);

        Storage::fake('local');
        Http::fake([
            '*/images/*' => Http::response($this->fakePngBytes(), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    public function test_invalid_signature_is_rejected_before_processing(): void
    {
        $generation = Generation::factory()->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

        $payload = $this->webhookEvent('generation.succeeded', $this->succeededGenerationPayload());
        [$body, $headers] = $this->signedWebhook($payload, secret: 'wrong_secret');

        $this->call('POST', '/webhooks/pixelforge', [], [], [], $headers, $body)->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(Generation::STATUS_QUEUED, $generation->fresh()->status);
    }

    public function test_expired_timestamp_is_rejected(): void
    {
        $payload = $this->webhookEvent('generation.succeeded', $this->succeededGenerationPayload());
        [$body, $headers] = $this->signedWebhook($payload, timestamp: time() - 301);

        $this->call('POST', '/webhooks/pixelforge', [], [], [], $headers, $body)->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_ping_is_acknowledged_without_storing_an_event(): void
    {
        $payload = [
            'id' => 'evt_ping_1',
            'object' => 'event',
            'type' => 'ping',
            'created_at' => '2026-11-04T14:03:29Z',
            'data' => [],
        ];

        [$body, $headers] = $this->signedWebhook($payload);

        $this->call('POST', '/webhooks/pixelforge', [], [], [], $headers, $body)->assertNoContent();

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_processing_event_moves_a_queued_generation_to_processing(): void
    {
        $generation = Generation::factory()->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

        $object = $this->queuedGenerationPayload(['metadata' => ['generation_id' => (string) $generation->id]]);
        $object['status'] = 'processing';

        $this->postWebhook($this->webhookEvent('generation.processing', $object))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_PROCESSING, $generation->status);
        $this->assertNull($generation->provider_cost);
        $this->assertNull($generation->customer_charge);
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, WebhookEvent::sole()->status);
    }

    public function test_succeeded_event_records_exact_financials_and_stores_the_image(): void
    {
        $generation = Generation::factory()->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

        $object = $this->succeededGenerationPayload(['metadata' => ['generation_id' => (string) $generation->id]]);

        $this->postWebhook($this->webhookEvent('generation.succeeded', $object))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('0.050750000', $generation->provider_cost);
        $this->assertSame('0.063437500', $generation->customer_charge);
        $this->assertSame('5.00', $generation->rates['input_text_tokens']);
        $this->assertSame(1700, $generation->usage['total_tokens']);
        $this->assertNotNull($generation->completed_at);
        $this->assertSame("generations/{$generation->id}.png", $generation->image_path);
        $this->assertTrue(Storage::disk('local')->exists($generation->image_path));
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, WebhookEvent::sole()->status);
    }

    public function test_duplicate_event_delivery_processes_once_and_never_double_charges(): void
    {
        $generation = Generation::factory()->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

        $object = $this->succeededGenerationPayload(['metadata' => ['generation_id' => (string) $generation->id]]);
        $event = $this->webhookEvent('generation.succeeded', $object, 'evt_duplicate_1');

        $this->postWebhook($event)->assertNoContent();
        $chargeAfterFirst = $generation->fresh()->customer_charge;

        $this->postWebhook($event)->assertNoContent();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame('0.063437500', $chargeAfterFirst);
        $this->assertSame($chargeAfterFirst, $generation->fresh()->customer_charge);
    }

    public function test_out_of_order_processing_event_does_not_downgrade_a_succeeded_generation(): void
    {
        $generation = Generation::factory()->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

        $succeeded = $this->succeededGenerationPayload(['metadata' => ['generation_id' => (string) $generation->id]]);
        $this->postWebhook($this->webhookEvent('generation.succeeded', $succeeded, 'evt_success_1'))->assertNoContent();

        $processing = $this->queuedGenerationPayload([
            'status' => 'processing',
            'metadata' => ['generation_id' => (string) $generation->id],
        ]);
        $this->postWebhook($this->webhookEvent('generation.processing', $processing, 'evt_processing_2'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('0.063437500', $generation->customer_charge);
    }

    public function test_failed_event_marks_the_generation_failed_and_free(): void
    {
        $generation = Generation::factory()->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

        $object = $this->failedGenerationPayload(['metadata' => ['generation_id' => (string) $generation->id]]);

        $this->postWebhook($this->webhookEvent('generation.failed', $object))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertNotNull($generation->completed_at);
        $this->assertNull($generation->provider_cost);
        $this->assertNull($generation->customer_charge);
    }

    public function test_early_webhook_is_correlated_through_metadata_before_the_submission_response(): void
    {
        $generation = Generation::factory()->create([
            'provider_generation_id' => null,
        ]);

        $object = $this->succeededGenerationPayload(['metadata' => ['generation_id' => (string) $generation->id]]);

        $this->postWebhook($this->webhookEvent('generation.succeeded', $object))->assertNoContent();

        $generation->refresh();
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->provider_generation_id);
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('0.063437500', $generation->customer_charge);
    }

    public function test_uncorrelated_event_is_accepted_but_does_not_touch_generations(): void
    {
        $object = $this->succeededGenerationPayload(['metadata' => ['generation_id' => '999999']]);

        $this->postWebhook($this->webhookEvent('generation.succeeded', $object))->assertNoContent();

        $this->assertSame(0, Generation::count());
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_submission_uncertain_generation_is_reconciled_by_a_late_webhook(): void
    {
        $generation = Generation::factory()->create([
            'status' => Generation::STATUS_FAILED,
            'provider_generation_id' => null,
            'error_code' => 'submission_uncertain',
            'error_message' => 'The submission outcome could not be confirmed.',
            'completed_at' => now(),
        ]);

        $object = $this->succeededGenerationPayload(['metadata' => ['generation_id' => (string) $generation->id]]);

        $this->postWebhook($this->webhookEvent('generation.succeeded', $object))->assertNoContent();

        $generation->refresh();
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->provider_generation_id);
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('0.063437500', $generation->customer_charge);
        $this->assertNull($generation->error_code);
    }

    public function test_provider_failed_generation_is_not_revived_by_a_late_success(): void
    {
        $generation = Generation::factory()->failed()->create([
            'provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'error_code' => 'generation_failed',
        ]);

        $object = $this->succeededGenerationPayload(['metadata' => ['generation_id' => (string) $generation->id]]);

        $this->postWebhook($this->webhookEvent('generation.succeeded', $object))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertNull($generation->customer_charge);
    }

    public function test_succeeded_generation_is_not_moved_back_to_failed(): void
    {
        $generation = Generation::factory()->succeeded()->create([
            'provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        ]);

        $object = $this->failedGenerationPayload(['metadata' => ['generation_id' => (string) $generation->id]]);

        $this->postWebhook($this->webhookEvent('generation.failed', $object))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('0.063437500', $generation->customer_charge);
    }

    public function test_succeeded_event_uses_generation_specific_rates(): void
    {
        $generation = Generation::factory()->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

        $object = $this->succeededGenerationPayload([
            'metadata' => ['generation_id' => (string) $generation->id],
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '45.00',
                'reasoning_tokens' => '12.50',
            ],
            'usage' => [
                'input_text_tokens' => 0,
                'input_image_tokens' => 0,
                'output_tokens' => 1000,
                'reasoning_tokens' => 0,
                'total_tokens' => 1000,
            ],
        ]);

        $this->postWebhook($this->webhookEvent('generation.succeeded', $object))->assertNoContent();

        $generation->refresh();
        $this->assertSame('45.00', $generation->rates['output_tokens']);
        $this->assertSame('0.045000000', $generation->provider_cost);
        $this->assertSame('0.056250000', $generation->customer_charge);
    }

    public function test_duplicate_success_with_newer_rates_does_not_change_historical_financials(): void
    {
        $generation = Generation::factory()->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

        $first = $this->succeededGenerationPayload(['metadata' => ['generation_id' => (string) $generation->id]]);
        $this->postWebhook($this->webhookEvent('generation.succeeded', $first, 'evt_success_first'))->assertNoContent();

        $second = $this->succeededGenerationPayload([
            'metadata' => ['generation_id' => (string) $generation->id],
            'rates' => [
                'input_text_tokens' => '99.00',
                'input_image_tokens' => '99.00',
                'output_tokens' => '99.00',
                'reasoning_tokens' => '99.00',
            ],
            'usage' => [
                'input_text_tokens' => 9999,
                'input_image_tokens' => 9999,
                'output_tokens' => 9999,
                'reasoning_tokens' => 9999,
                'total_tokens' => 39996,
            ],
        ]);
        $this->postWebhook($this->webhookEvent('generation.succeeded', $second, 'evt_success_second'))->assertNoContent();

        $generation->refresh();
        $this->assertSame('40.00', $generation->rates['output_tokens']);
        $this->assertSame('0.050750000', $generation->provider_cost);
        $this->assertSame('0.063437500', $generation->customer_charge);
        $this->assertSame(1700, $generation->usage['total_tokens']);
    }

    public function test_failed_event_remains_free_even_when_usage_is_present(): void
    {
        $generation = Generation::factory()->create(['provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

        $object = $this->failedGenerationPayload([
            'metadata' => ['generation_id' => (string) $generation->id],
            'usage' => [
                'input_text_tokens' => 100,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 500,
                'total_tokens' => 1700,
            ],
        ]);

        $this->postWebhook($this->webhookEvent('generation.failed', $object))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertNull($generation->provider_cost);
        $this->assertNull($generation->customer_charge);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        [$body, $headers] = $this->signedWebhook($payload, $timestamp, $secret);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], $headers, $body);
    }
}
