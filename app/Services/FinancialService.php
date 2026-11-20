<?php

namespace App\Services;

use InvalidArgumentException;

class FinancialService
{
    private const string HALF_NANODOLLAR = '0.0000000005';

    private const array TOKEN_TYPES = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    /**
     * Provider cost = SUM(token_count × rate) / 1,000,000, exact decimal string.
     *
     * @param  array<string, int|null>  $usage
     * @param  array<string, string>  $rates
     */
    public function providerCost(array $usage, array $rates): string
    {
        $total = '0.000000000';

        foreach (self::TOKEN_TYPES as $tokenType) {
            $count = (int) ($usage[$tokenType] ?? 0);

            if ($count <= 0) {
                continue;
            }

            if (! isset($rates[$tokenType])) {
                throw new InvalidArgumentException("Missing provider rate for {$tokenType}.");
            }

            $total = bcadd(
                $total,
                bcdiv(bcmul((string) $count, (string) $rates[$tokenType], 12), '1000000', 12),
                12,
            );
        }

        return $this->roundToNanodollars($total);
    }

    /**
     * Customer charge = provider cost × 1.25, rounded half-up to nanodollars.
     */
    public function customerCharge(string $providerCost): string
    {
        return $this->roundToNanodollars(bcmul($providerCost, '1.25', 12));
    }

    private function roundToNanodollars(string $amount): string
    {
        return bcadd($amount, self::HALF_NANODOLLAR, 9);
    }
}
