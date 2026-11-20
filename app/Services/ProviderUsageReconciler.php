<?php

namespace App\Services;

use App\Models\Generation;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class ProviderUsageReconciler
{
    private const int MAX_RANGE_DAYS = 92;

    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @return array{
     *     start_date: string,
     *     end_date: string,
     *     days: list<array{date: string, provider_generations: int, internal_generations: int, provider_cost: string, internal_cost: string, difference: string}>,
     *     provider_total: string,
     *     internal_total: string,
     *     total_difference: string,
     *     matches: bool
     * }
     */
    public function reconcile(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $start = $startDate->utc()->startOfDay();
        $end = $endDate->utc()->startOfDay();

        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('The start date must not be after the end date.');
        }

        if ((int) $start->diffInDays($end) + 1 > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('The reconciliation range must not exceed 92 days.');
        }

        $usage = $this->client->usage($start->toDateString(), $end->toDateString());
        $internal = $this->internalCosts($start, $end);

        $days = [];
        $seen = [];
        $internalTotal = '0.000000000';
        $matches = true;

        foreach ($usage['data'] as $row) {
            $date = (string) $row['date'];
            $seen[$date] = true;

            $providerCost = $this->normalize((string) $row['cost_usd']);
            $internalCost = $internal[$date]['cost'] ?? '0.000000000';
            $difference = bcsub($internalCost, $providerCost, 9);

            if (bccomp($difference, '0.000000000', 9) !== 0) {
                $matches = false;
            }

            $days[] = [
                'date' => $date,
                'provider_generations' => (int) ($row['generations'] ?? 0),
                'internal_generations' => $internal[$date]['count'] ?? 0,
                'provider_cost' => $providerCost,
                'internal_cost' => $internalCost,
                'difference' => $difference,
            ];
        }

        foreach ($internal as $date => $entry) {
            if (isset($seen[$date])) {
                continue;
            }

            $difference = bcsub($entry['cost'], '0.000000000', 9);

            if (bccomp($difference, '0.000000000', 9) !== 0) {
                $matches = false;
            }

            $days[] = [
                'date' => $date,
                'provider_generations' => 0,
                'internal_generations' => $entry['count'],
                'provider_cost' => '0.000000000',
                'internal_cost' => $entry['cost'],
                'difference' => $difference,
            ];
        }

        usort($days, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        foreach ($internal as $entry) {
            $internalTotal = bcadd($internalTotal, $entry['cost'], 9);
        }

        $providerTotal = $this->normalize((string) ($usage['total']['cost_usd'] ?? '0.000000000'));
        $totalDifference = bcsub($internalTotal, $providerTotal, 9);

        if (bccomp($totalDifference, '0.000000000', 9) !== 0) {
            $matches = false;
        }

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => $days,
            'provider_total' => $providerTotal,
            'internal_total' => $internalTotal,
            'total_difference' => $totalDifference,
            'matches' => $matches,
        ];
    }

    /**
     * @return array<string, array{cost: string, count: int}>
     */
    private function internalCosts(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $totals = [];

        Generation::query()
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start, $end->endOfDay()])
            ->get(['completed_at', 'provider_cost'])
            ->each(function (Generation $generation) use (&$totals): void {
                $date = $generation->completed_at->utc()->toDateString();
                $cost = $this->normalize((string) ($generation->provider_cost ?? '0'));

                $totals[$date] ??= ['cost' => '0.000000000', 'count' => 0];
                $totals[$date]['cost'] = bcadd($totals[$date]['cost'], $cost, 9);
                $totals[$date]['count']++;
            });

        return $totals;
    }

    private function normalize(string $amount): string
    {
        return bcadd($amount, '0.000000000', 9);
    }
}
