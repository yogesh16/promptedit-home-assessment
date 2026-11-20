<?php

namespace Tests\Unit;

use App\Services\FinancialService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FinancialServiceTest extends TestCase
{
    private FinancialService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FinancialService;
    }

    public function test_provider_cost_matches_documented_usage_example(): void
    {
        $cost = $this->service->providerCost(
            [
                'input_text_tokens' => 41,
                'input_image_tokens' => 0,
                'output_tokens' => 3300,
                'reasoning_tokens' => 2950,
            ],
            $this->rates(),
        );

        $this->assertSame('0.169080000', $cost);
    }

    public function test_provider_cost_includes_all_token_types(): void
    {
        $cost = $this->service->providerCost(
            [
                'input_text_tokens' => 115,
                'input_image_tokens' => 1530,
                'output_tokens' => 6450,
                'reasoning_tokens' => 8000,
            ],
            $this->rates(),
        );

        $this->assertSame('0.373875000', $cost);
    }

    public function test_provider_cost_of_empty_usage_is_zero(): void
    {
        $this->assertSame('0.000000000', $this->service->providerCost([], $this->rates()));
    }

    public function test_customer_charge_applies_exactly_25_percent_markup(): void
    {
        $this->assertSame('0.063437500', $this->service->customerCharge('0.050750000'));
        $this->assertSame('0.467343750', $this->service->customerCharge('0.373875000'));
        $this->assertSame('0.211350000', $this->service->customerCharge('0.169080000'));
    }

    public function test_customer_charge_of_zero_is_zero(): void
    {
        $this->assertSame('0.000000000', $this->service->customerCharge('0.000000000'));
    }

    public function test_missing_rate_for_nonzero_tokens_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->providerCost(['output_tokens' => 100], []);
    }

    /**
     * @return array<string, string>
     */
    private function rates(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }
}
