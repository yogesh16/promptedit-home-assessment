<?php

namespace App\Services;

use App\Exceptions\PixelforgeApiException;

class PricingService
{
    private const int CACHE_TTL_SECONDS = 600;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly FinancialService $financial,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     */
    public function pricing(): array
    {
        /** @var array<string, mixed> */
        return cache()->remember(
            'pixelforge.pricing',
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->client->pricing(),
        );
    }

    /**
     * Estimate customer charge range (including 25% markup) from Pixelforge pricing rules.
     *
     * @param  list<string>  $referenceImages
     * @return array{estimated_cost_min: string, estimated_cost_max: string}
     *
     * @throws PixelforgeApiException
     */
    public function estimate(string $prompt, string $size, string $quality, array $referenceImages): array
    {
        $pricing = $this->pricing();
        /** @var array<string, string> $rates */
        $rates = $pricing['rates'];
        /** @var array<string, mixed> $rules */
        $rules = $pricing['tokens'];

        $textTokens = $this->textTokenEstimate(
            $prompt,
            (int) $rules['characters_per_text_token'],
            (string) $rules['text_token_variance'],
        );
        $imageTokens = count($referenceImages) * (int) $rules['per_reference_image'];
        $outputTokens = (int) $rules['output'][$quality][$size];
        $reasoningMin = (int) $rules['reasoning'][$quality]['min'];
        $reasoningMax = (int) $rules['reasoning'][$quality]['max'];

        $providerMin = $this->financial->providerCost([
            'input_text_tokens' => $textTokens['min'],
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningMin,
        ], $rates);

        $providerMax = $this->financial->providerCost([
            'input_text_tokens' => $textTokens['max'],
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningMax,
        ], $rates);

        return [
            'estimated_cost_min' => $this->financial->customerCharge($providerMin),
            'estimated_cost_max' => $this->financial->customerCharge($providerMax),
        ];
    }

    /**
     * @return array{min: int, max: int}
     */
    private function textTokenEstimate(string $prompt, int $charactersPerToken, string $variance): array
    {
        $characters = mb_strlen($prompt, 'UTF-8');
        $estimate = intdiv($characters + $charactersPerToken - 1, $charactersPerToken);

        $minProduct = bcmul((string) $estimate, bcsub('1', $variance, 6), 6);
        $maxProduct = bcmul((string) $estimate, bcadd('1', $variance, 6), 6);

        $min = (int) bcadd($minProduct, '0', 0);
        $maxFloor = (int) bcadd($maxProduct, '0', 0);
        $max = bccomp($maxProduct, (string) $maxFloor, 6) > 0 ? $maxFloor + 1 : $maxFloor;

        return [
            'min' => $min,
            'max' => $max,
        ];
    }
}
