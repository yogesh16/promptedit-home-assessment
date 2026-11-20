<?php

namespace App\Services;

use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FinancialReportService
{
    /**
     * @return array{days: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function daily(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $buckets = [];

        foreach ($this->completedGenerations($start, $end)->get() as $generation) {
            $date = $generation->completed_at->utc()->toDateString();
            $buckets[$date] = $this->addToBucket($buckets[$date] ?? null, $generation);
        }

        $days = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $bucket = $buckets[$key] ?? $this->emptyBucket();
            $bucket['date'] = $key;
            $bucket['profit'] = bcsub($bucket['customer_charge'], $bucket['provider_cost'], 9);
            $days[] = $bucket;
        }

        return ['days' => $days, 'totals' => $this->totals($days)];
    }

    /**
     * @return array{users: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function perUser(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $buckets = [];

        foreach ($this->completedGenerations($start, $end)->with('user:id,name,email')->get() as $generation) {
            $userId = (int) $generation->user_id;
            $bucket = $buckets[$userId] ?? [
                ...$this->emptyBucket(),
                'user_id' => $userId,
                'name' => $generation->user?->name,
                'email' => $generation->user?->email,
            ];

            $buckets[$userId] = $this->addToBucket($bucket, $generation);
        }

        $users = array_values($buckets);

        usort($users, fn (array $a, array $b): int => bccomp($b['customer_charge'], $a['customer_charge'], 9));

        foreach ($users as $index => $user) {
            $users[$index]['profit'] = bcsub($user['customer_charge'], $user['provider_cost'], 9);
        }

        return ['users' => $users, 'totals' => $this->totals($users)];
    }

    /**
     * @return Builder<Generation>
     */
    private function completedGenerations(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Generation::query()
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start, $end->endOfDay()]);
    }

    /**
     * @param  array<string, mixed>|null  $bucket
     * @return array<string, mixed>
     */
    private function addToBucket(?array $bucket, Generation $generation): array
    {
        $bucket ??= $this->emptyBucket();
        $bucket['generations']++;
        $bucket['provider_cost'] = bcadd($bucket['provider_cost'], (string) ($generation->provider_cost ?? '0'), 9);
        $bucket['customer_charge'] = bcadd($bucket['customer_charge'], (string) ($generation->customer_charge ?? '0'), 9);

        return $bucket;
    }

    /**
     * @return array{generations: int, provider_cost: string, customer_charge: string, profit: string}
     */
    private function emptyBucket(): array
    {
        return [
            'generations' => 0,
            'provider_cost' => '0.000000000',
            'customer_charge' => '0.000000000',
            'profit' => '0.000000000',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>|list<array<string, mixed>>  $rows
     * @return array{generations: int, provider_cost: string, customer_charge: string, profit: string}
     */
    private function totals(iterable $rows): array
    {
        $totals = $this->emptyBucket();

        foreach ($rows as $row) {
            $totals['generations'] += $row['generations'];
            $totals['provider_cost'] = bcadd($totals['provider_cost'], $row['provider_cost'], 9);
            $totals['customer_charge'] = bcadd($totals['customer_charge'], $row['customer_charge'], 9);
        }

        $totals['profit'] = bcsub($totals['customer_charge'], $totals['provider_cost'], 9);

        return $totals;
    }
}
