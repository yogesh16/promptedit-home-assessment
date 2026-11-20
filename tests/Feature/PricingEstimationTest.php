<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\FakesPixelforge;
use Tests\TestCase;

class PricingEstimationTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    public function test_estimate_matches_documented_worked_example_with_customer_markup(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);

        $response = $this->actingAs(User::factory()->create())->postJson('/generations/estimate', [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [
                'https://example.com/style-a.png',
                'https://example.com/style-b.png',
            ],
        ]);

        // Provider range: 0.286225000 – 0.373875000; customer charge = provider × 1.25.
        $response->assertOk()->assertJson([
            'estimated_cost_min' => '0.357781250',
            'estimated_cost_max' => '0.467343750',
            'currency' => 'usd',
        ]);
    }

    public function test_estimate_applies_text_variance_and_quality_floor(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);

        $response = $this->actingAs(User::factory()->create())->postJson('/generations/estimate', [
            'prompt' => 'aaaa',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
        ]);

        // Provider range: 0.011200000 – 0.017460000; customer charge = provider × 1.25.
        $response->assertOk()->assertJson([
            'estimated_cost_min' => '0.014000000',
            'estimated_cost_max' => '0.021825000',
        ]);
    }

    public function test_estimate_counts_unicode_characters_not_bytes(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);

        $user = User::factory()->create();

        $ascii = $this->actingAs($user)->postJson('/generations/estimate', [
            'prompt' => str_repeat('a', 400),
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
        ])->assertOk()->json();

        $unicode = $this->actingAs($user)->postJson('/generations/estimate', [
            'prompt' => str_repeat('🎨', 400),
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
        ])->assertOk()->json();

        $this->assertSame($ascii['estimated_cost_min'], $unicode['estimated_cost_min']);
        $this->assertSame($ascii['estimated_cost_max'], $unicode['estimated_cost_max']);
    }

    public function test_each_reference_image_costs_exactly_765_input_tokens_plus_markup(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);

        $user = User::factory()->create();

        $without = $this->actingAs($user)->postJson('/generations/estimate', [
            'prompt' => 'aaaa',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
        ])->json();

        $with = $this->actingAs($user)->postJson('/generations/estimate', [
            'prompt' => 'aaaa',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => ['https://example.com/ref.png'],
        ])->json();

        $difference = bcsub($with['estimated_cost_max'], $without['estimated_cost_max'], 9);

        // 765 tokens × $10.00 / 1M × 1.25 = $0.009562500.
        $this->assertSame('0.009562500', $difference);
    }

    public function test_pricing_is_cached_between_estimates(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);

        $user = User::factory()->create();
        $payload = [
            'prompt' => 'A quiet harbor at sunrise',
            'size' => '1024x1024',
            'quality' => 'medium',
        ];

        $this->actingAs($user)->postJson('/generations/estimate', $payload)->assertOk();
        $this->actingAs($user)->postJson('/generations/estimate', $payload)->assertOk();

        Http::assertSentCount(1);
    }

    public function test_guests_cannot_estimate(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);

        $this->postJson('/generations/estimate', [
            'prompt' => 'A quiet harbor at sunrise',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertUnauthorized();
    }

    public function test_invalid_parameters_are_rejected(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/generations/estimate', [
            'prompt' => 'A quiet harbor at sunrise',
            'size' => '999x999',
            'quality' => 'ultra',
        ])->assertUnprocessable()->assertJsonValidationErrors(['size', 'quality']);
    }

    public function test_unavailable_pricing_returns_a_structured_error(): void
    {
        Http::fake(['*/pricing' => Http::response([], 500)]);

        $this->actingAs(User::factory()->create())->postJson('/generations/estimate', [
            'prompt' => 'A quiet harbor at sunrise',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertUnprocessable()->assertJsonValidationErrors(['pricing']);
    }
}
