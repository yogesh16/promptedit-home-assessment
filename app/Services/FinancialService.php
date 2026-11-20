<?php

namespace App\Services;

use InvalidArgumentException;

class FinancialService
{
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

        return bcadd($total, '0', 9);
    }

    /**
     * Customer charge = provider cost × 1.25, exact decimal string.
     */
    public function customerCharge(string $providerCost): string
    {
        return bcmul($providerCost, '1.25', 9);
    }
}
