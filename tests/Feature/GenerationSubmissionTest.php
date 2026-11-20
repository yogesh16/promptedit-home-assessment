<?php

namespace Tests\Feature;

use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\PixelforgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\FakesPixelforge;
use Tests\TestCase;

class GenerationSubmissionTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixelforge.api_key' => 'pf_test_key']);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->post('/generations', $this->validPayload())->assertRedirect('/login');
    }

    public function test_invalid_parameters_are_rejected(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/generations', [
            'prompt' => 'A quiet harbor at sunrise',
            'size' => '999x999',
            'quality' => 'ultra',
            'reference_images' => ['ftp://example.com/image.png'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['size', 'quality', 'reference_images.0']);

        $this->actingAs($user)->postJson('/generations', [
            'prompt' => str_repeat('a', 4001),
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertUnprocessable()->assertJsonValidationErrors(['prompt']);

        $this->actingAs($user)->postJson('/generations', [
            'prompt' => 'A quiet harbor at sunrise',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [
                'https://example.com/1.png',
                'https://example.com/2.png',
                'https://example.com/3.png',
                'https://example.com/4.png',
                'https://example.com/5.png',
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['reference_images']);

        $this->assertSame(0, Generation::count());
    }

    public function test_valid_submission_creates_a_queued_generation_and_dispatches_the_job(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);
        Queue::fake();

        $response = $this->actingAs(User::factory()->create())->post('/generations', $this->validPayload());

        $response->assertRedirect()->assertSessionHas('success');

        $generation = Generation::sole();
        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertTrue(Str::isUuid($generation->idempotency_key));
        $this->assertNotNull($generation->estimated_cost_min);
        $this->assertNotNull($generation->estimated_cost_max);
        $this->assertNull($generation->provider_generation_id);
        $this->assertNull($generation->provider_cost);
        $this->assertNull($generation->customer_charge);

        Queue::assertPushed(SubmitGenerationJob::class, fn ($job) => $job->generationId === $generation->id);
    }

    public function test_successful_submission_persists_provider_identifiers_and_rates(): void
    {
        Http::fake([
            '*/pricing' => Http::response($this->pricingPayload()),
            '*/generations' => Http::response($this->queuedGenerationPayload(), 202),
        ]);

        $this->actingAs(User::factory()->create())->post('/generations', $this->validPayload())
            ->assertRedirect();

        $generation = Generation::sole();
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->provider_generation_id);
        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertSame('5.00', $generation->rates['input_text_tokens']);
        $this->assertNull($generation->provider_cost);
        $this->assertNull($generation->customer_charge);

        Http::assertSent(function ($request) use ($generation) {
            if (! str_contains($request->url(), '/v1/generations') || $request->method() !== 'POST') {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer pf_test_key')
                && $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
                && $request['metadata']['generation_id'] === (string) $generation->id
                && $request['prompt'] === $generation->prompt
                && ! isset($request['reference_images']);
        });
    }

    public function test_reference_images_are_forwarded_to_the_provider(): void
    {
        Http::fake([
            '*/pricing' => Http::response($this->pricingPayload()),
            '*/generations' => Http::response($this->queuedGenerationPayload(), 202),
        ]);

        $references = [
            'https://example.com/a.png',
            'https://example.com/b.png',
        ];

        $this->actingAs(User::factory()->create())->post('/generations', [
            ...$this->validPayload(),
            'reference_images' => $references,
        ])->assertRedirect();

        Http::assertSent(function ($request) use ($references) {
            return str_contains($request->url(), '/v1/generations')
                && $request['reference_images'] === $references;
        });
    }

    public function test_content_policy_violation_marks_the_generation_failed_and_free(): void
    {
        Http::fake([
            '*/pricing' => Http::response($this->pricingPayload()),
            '*/generations' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'content_policy_violation',
                    'message' => 'The prompt was rejected.',
                    'param' => 'prompt',
                ],
            ], 400),
        ]);

        $this->actingAs(User::factory()->create())->post('/generations', $this->validPayload())
            ->assertRedirect();

        $generation = Generation::sole();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertNotNull($generation->completed_at);
        $this->assertNull($generation->provider_cost);
        $this->assertNull($generation->customer_charge);
    }

    public function test_invalid_api_key_fails_immediately_without_retry(): void
    {
        Http::fake([
            '*/pricing' => Http::response($this->pricingPayload()),
            '*/generations' => Http::response([
                'error' => [
                    'type' => 'authentication_error',
                    'code' => 'invalid_api_key',
                    'message' => 'The API key is missing, malformed or revoked.',
                ],
            ], 401),
        ]);

        $this->actingAs(User::factory()->create())->post('/generations', $this->validPayload())
            ->assertRedirect();

        $generation = Generation::sole();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('invalid_api_key', $generation->error_code);
        Http::assertSentCount(2);
    }

    public function test_idempotency_conflict_fails_investigation_state_without_retry(): void
    {
        Http::fake([
            '*/pricing' => Http::response($this->pricingPayload()),
            '*/generations' => Http::response([
                'error' => [
                    'type' => 'idempotency_error',
                    'code' => 'idempotency_key_reused',
                    'message' => 'The Idempotency-Key was already used with different parameters.',
                ],
            ], 409),
        ]);

        $this->actingAs(User::factory()->create())->post('/generations', $this->validPayload())
            ->assertRedirect();

        $generation = Generation::sole();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('idempotency_key_reused', $generation->error_code);
        Http::assertSentCount(2);
    }

    public function test_retryable_failure_keeps_the_generation_queued_and_reuses_the_same_idempotency_key(): void
    {
        Http::fake([
            '*/pricing' => Http::response($this->pricingPayload()),
            '*/generations' => Http::sequence()
                ->push(['error' => ['code' => 'overloaded', 'message' => 'Pixelforge is temporarily overloaded.']], 503)
                ->push($this->queuedGenerationPayload(), 202),
        ]);

        $this->actingAs(User::factory()->create())->post('/generations', $this->validPayload())
            ->assertRedirect();

        $generation = Generation::sole();
        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertNull($generation->provider_generation_id);
        $this->assertNull($generation->error_code);

        (new SubmitGenerationJob($generation->id))->handle(app(PixelforgeClient::class));

        $generation->refresh();
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->provider_generation_id);

        $keys = array_values(Http::recorded(fn ($request) => str_contains($request->url(), '/v1/generations') && $request->method() === 'POST')
            ->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0] ?? null)
            ->all());

        $this->assertCount(2, $keys);
        $this->assertSame($generation->idempotency_key, $keys[0]);
        $this->assertSame($generation->idempotency_key, $keys[1]);
    }

    public function test_exhausted_retries_mark_the_generation_failed(): void
    {
        Http::fake([
            '*/generations' => Http::response([
                'error' => ['code' => 'overloaded', 'message' => 'Pixelforge is temporarily overloaded.'],
            ], 503),
        ]);

        $generation = Generation::factory()->create();

        $job = new class($generation->id) extends SubmitGenerationJob
        {
            public function attempts(): int
            {
                return 5;
            }
        };

        $job->handle(app(PixelforgeClient::class));

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('overloaded', $generation->error_code);
        $this->assertNull($generation->customer_charge);
    }

    public function test_connection_failure_is_treated_as_retryable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $generation = Generation::factory()->create();

        (new SubmitGenerationJob($generation->id))->handle(app(PixelforgeClient::class));

        $generation->refresh();
        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertNull($generation->error_code);
        $this->assertNull($generation->provider_generation_id);
    }

    public function test_uncertain_connection_failure_recovers_via_idempotent_replay(): void
    {
        $attempt = 0;

        Http::fake(function () use (&$attempt) {
            $attempt++;

            if ($attempt === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response($this->queuedGenerationPayload(), 202, [
                'Idempotent-Replayed' => 'true',
            ]);
        });

        $generation = Generation::factory()->create();

        (new SubmitGenerationJob($generation->id))->handle(app(PixelforgeClient::class));
        $generation->refresh();
        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertNull($generation->provider_generation_id);

        (new SubmitGenerationJob($generation->id))->handle(app(PixelforgeClient::class));
        $generation->refresh();
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->provider_generation_id);
        $this->assertSame('5.00', $generation->rates['input_text_tokens']);
    }

    public function test_exhausted_uncertain_failure_marks_submission_uncertain_without_charging(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $generation = Generation::factory()->create();

        $job = new class($generation->id) extends SubmitGenerationJob
        {
            public function attempts(): int
            {
                return 5;
            }
        };

        $job->handle(app(PixelforgeClient::class));

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('submission_uncertain', $generation->error_code);
        $this->assertNull($generation->provider_cost);
        $this->assertNull($generation->customer_charge);
    }

    public function test_daily_limit_exceeded_fails_immediately_without_retry(): void
    {
        Http::fake([
            '*/pricing' => Http::response($this->pricingPayload()),
            '*/generations' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'code' => 'daily_limit_exceeded',
                    'message' => 'The daily generation quota (UTC) is used up.',
                ],
            ], 429),
        ]);

        $this->actingAs(User::factory()->create())->post('/generations', $this->validPayload())
            ->assertRedirect();

        $generation = Generation::sole();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('daily_limit_exceeded', $generation->error_code);
        $this->assertNull($generation->customer_charge);
        Http::assertSentCount(2);
    }

    public function test_rate_limit_exceeded_respects_retry_after_and_backoff(): void
    {
        Http::fake([
            '*/generations' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'code' => 'rate_limit_exceeded',
                    'message' => 'Too many requests this minute.',
                ],
            ], 429, ['Retry-After' => '42']),
        ]);

        $generation = Generation::factory()->create();

        $job = new class($generation->id) extends SubmitGenerationJob
        {
            public ?int $releasedDelay = null;

            public function release($delay = 0): void
            {
                $this->releasedDelay = is_int($delay) ? $delay : null;
            }
        };

        $job->handle(app(PixelforgeClient::class));

        $this->assertSame(42, $job->releasedDelay);
        $this->assertSame(Generation::STATUS_QUEUED, $generation->fresh()->status);
    }

    public function test_concurrency_limit_exceeded_backs_off_beyond_small_retry_after(): void
    {
        Http::fake([
            '*/generations' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'code' => 'concurrency_limit_exceeded',
                    'message' => 'Too many generations queued or processing.',
                ],
            ], 429, ['Retry-After' => '5']),
        ]);

        $generation = Generation::factory()->create();

        $job = new class($generation->id) extends SubmitGenerationJob
        {
            public ?int $releasedDelay = null;

            public function attempts(): int
            {
                return 3;
            }

            public function release($delay = 0): void
            {
                $this->releasedDelay = is_int($delay) ? $delay : null;
            }
        };

        $job->handle(app(PixelforgeClient::class));

        $this->assertSame(30, $job->releasedDelay);
        $this->assertSame(Generation::STATUS_QUEUED, $generation->fresh()->status);
    }

    public function test_exhausted_failure_does_not_overwrite_generation_updated_by_early_webhook(): void
    {
        $generation = Generation::factory()->create();

        Http::fake(function () use ($generation) {
            // Simulate an early webhook arriving while the HTTP call times out.
            $generation->update([
                'provider_generation_id' => 'gen_early_webhook_123',
                'status' => Generation::STATUS_PROCESSING,
            ]);

            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $job = new class($generation->id) extends SubmitGenerationJob
        {
            public function attempts(): int
            {
                return 5;
            }
        };

        $job->handle(app(PixelforgeClient::class));

        $generation->refresh();
        $this->assertSame(Generation::STATUS_PROCESSING, $generation->status);
        $this->assertSame('gen_early_webhook_123', $generation->provider_generation_id);
        $this->assertNull($generation->error_code);
    }

    public function test_response_persistence_does_not_downgrade_early_webhook_state(): void
    {
        $generation = Generation::factory()->create();

        Http::fake(function () use ($generation) {
            $generation->update([
                'provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
                'status' => Generation::STATUS_SUCCEEDED,
                'rates' => [
                    'input_text_tokens' => '4.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'provider_cost' => '0.050750000',
                'customer_charge' => '0.063437500',
                'completed_at' => now(),
            ]);

            return Http::response($this->queuedGenerationPayload(), 202);
        });

        (new SubmitGenerationJob($generation->id))->handle(app(PixelforgeClient::class));

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('4.00', $generation->rates['input_text_tokens']);
        $this->assertSame('0.063437500', $generation->customer_charge);
    }

    public function test_duplicate_submission_retry_does_not_create_a_second_generation(): void
    {
        Http::fake([
            '*/pricing' => Http::response($this->pricingPayload()),
            '*/generations' => Http::response($this->queuedGenerationPayload(), 202, [
                'Idempotent-Replayed' => 'true',
            ]),
        ]);

        $this->actingAs(User::factory()->create())->post('/generations', $this->validPayload())
            ->assertRedirect();

        $generation = Generation::sole();
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->provider_generation_id);

        (new SubmitGenerationJob($generation->id))->handle(app(PixelforgeClient::class));
        (new SubmitGenerationJob($generation->id))->handle(app(PixelforgeClient::class));

        $this->assertSame(1, Generation::count());
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->fresh()->provider_generation_id);
        $this->assertNull($generation->fresh()->customer_charge);
        Http::assertSentCount(2);
    }

    public function test_pricing_failure_aborts_before_creating_a_generation(): void
    {
        Http::fake(['*/pricing' => Http::response([], 500)]);

        $this->actingAs(User::factory()->create())->post('/generations', $this->validPayload())
            ->assertSessionHasErrors('pricing');

        $this->assertSame(0, Generation::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [],
        ];
    }
}
