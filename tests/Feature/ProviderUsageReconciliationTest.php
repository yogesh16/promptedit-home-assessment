<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Services\ProviderUsageReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\FakesPixelforge;
use Tests\TestCase;

class ProviderUsageReconciliationTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
            'services.pixelforge.api_key' => 'pf_test_key',
        ]);
    }

    public function test_reconciliation_matches_and_preserves_the_provider_decimal_string(): void
    {
        $this->fakeUsage([
            $this->usageDay('2026-11-01', 3, '0.169080000'),
            $this->usageDay('2026-11-02', 0, '0.000000000'),
        ], '0.169080000');

        Generation::factory()->succeeded()->create([
            'provider_generation_id' => 'gen_usage_1',
            'provider_cost' => '0.169080000',
            'customer_charge' => '0.211350000',
            'completed_at' => CarbonImmutable::parse('2026-11-01T15:30:00Z'),
        ]);

        $result = app(ProviderUsageReconciler::class)->reconcile(
            CarbonImmutable::parse('2026-11-01'),
            CarbonImmutable::parse('2026-11-02'),
        );

        $this->assertTrue($result['matches']);
        $this->assertSame('0.169080000', $result['days'][0]['provider_cost']);
        $this->assertSame('0.169080000', $result['days'][0]['internal_cost']);
        $this->assertSame('0.000000000', $result['days'][0]['difference']);
        $this->assertSame('0.169080000', $result['provider_total']);
        $this->assertSame('0.169080000', $result['internal_total']);

        $this->artisan('pixelforge:reconcile', ['--start' => '2026-11-01', '--end' => '2026-11-02'])
            ->assertSuccessful();
    }

    public function test_reconciliation_reports_a_discrepancy_without_modifying_records(): void
    {
        $this->fakeUsage([
            $this->usageDay('2026-11-01', 1, '0.169080000'),
        ], '0.169080000');

        $generation = Generation::factory()->succeeded()->create([
            'provider_generation_id' => 'gen_usage_2',
            'provider_cost' => '0.100000000',
            'customer_charge' => '0.125000000',
            'completed_at' => CarbonImmutable::parse('2026-11-01T15:30:00Z'),
        ]);

        $this->artisan('pixelforge:reconcile', ['--start' => '2026-11-01', '--end' => '2026-11-01'])
            ->assertFailed();

        $generation->refresh();
        $this->assertSame('0.100000000', $generation->provider_cost);
        $this->assertSame('0.125000000', $generation->customer_charge);
    }

    public function test_usage_is_grouped_by_completion_date_not_creation_date(): void
    {
        $this->fakeUsage([
            $this->usageDay('2026-10-31', 0, '0.000000000'),
            $this->usageDay('2026-11-01', 3, '0.169080000'),
        ], '0.169080000');

        Generation::factory()->succeeded()->create([
            'provider_generation_id' => 'gen_usage_3',
            'provider_cost' => '0.169080000',
            'customer_charge' => '0.211350000',
            'created_at' => CarbonImmutable::parse('2026-10-31T23:59:00Z'),
            'completed_at' => CarbonImmutable::parse('2026-11-01T00:05:00Z'),
        ]);

        $result = app(ProviderUsageReconciler::class)->reconcile(
            CarbonImmutable::parse('2026-10-31'),
            CarbonImmutable::parse('2026-11-01'),
        );

        $this->assertTrue($result['matches']);
        $this->assertSame('0.000000000', $result['days'][0]['internal_cost']);
        $this->assertSame('0.169080000', $result['days'][1]['internal_cost']);
    }

    public function test_range_longer_than_92_days_is_rejected_without_calling_the_provider(): void
    {
        Http::fake();

        $this->artisan('pixelforge:reconcile', ['--start' => '2026-01-01', '--end' => '2026-04-03'])
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_provider_api_failure_is_reported(): void
    {
        Http::fake(['*/v1/usage*' => Http::response([
            'error' => ['code' => 'invalid_api_key', 'message' => 'The API key is invalid.'],
        ], 401)]);

        $this->artisan('pixelforge:reconcile', ['--start' => '2026-11-01', '--end' => '2026-11-02'])
            ->assertFailed();
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    private function fakeUsage(array $data, string $totalCost): void
    {
        Http::fake([
            '*/v1/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => $data[0]['date'] ?? '2026-11-01',
                'end_date' => $data[count($data) - 1]['date'] ?? '2026-11-01',
                'data' => $data,
                'total' => [
                    'generations' => array_sum(array_column($data, 'generations')),
                    'input_text_tokens' => array_sum(array_column($data, 'input_text_tokens')),
                    'input_image_tokens' => array_sum(array_column($data, 'input_image_tokens')),
                    'output_tokens' => array_sum(array_column($data, 'output_tokens')),
                    'reasoning_tokens' => array_sum(array_column($data, 'reasoning_tokens')),
                    'cost_usd' => $totalCost,
                ],
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function usageDay(string $date, int $generations, string $cost): array
    {
        return [
            'date' => $date,
            'generations' => $generations,
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
            'cost_usd' => $cost,
        ];
    }
}
