<?php

namespace App\Console\Commands;

use App\Exceptions\PixelforgeApiException;
use App\Services\ProviderUsageReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class ReconcilePixelforgeUsage extends Command
{
    protected $signature = 'pixelforge:reconcile
        {--start= : UTC start date (YYYY-MM-DD)}
        {--end= : UTC end date (YYYY-MM-DD)}';

    protected $description = 'Reconcile internal provider costs against the Pixelforge usage report.';

    public function handle(ProviderUsageReconciler $reconciler): int
    {
        try {
            $end = $this->option('end') !== null
                ? CarbonImmutable::parse((string) $this->option('end'), 'UTC')
                : CarbonImmutable::now('UTC')->startOfDay();

            $start = $this->option('start') !== null
                ? CarbonImmutable::parse((string) $this->option('start'), 'UTC')
                : $end->subDays(29);
        } catch (Throwable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return self::FAILURE;
        }

        try {
            $result = $reconciler->reconcile($start, $end);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (PixelforgeApiException $e) {
            $this->error('Could not retrieve Pixelforge usage: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['UTC date', 'Provider gens', 'Internal gens', 'Provider cost', 'Internal cost', 'Difference'],
            array_map(fn (array $day): array => [
                $day['date'],
                $day['provider_generations'],
                $day['internal_generations'],
                $day['provider_cost'],
                $day['internal_cost'],
                $day['difference'],
            ], $result['days']),
        );

        $this->line("Provider total: {$result['provider_total']}");
        $this->line("Internal total: {$result['internal_total']}");
        $this->line("Difference: {$result['total_difference']}");

        if (! $result['matches']) {
            $this->warn('Provider usage does not match internal records. Historical financial records were not modified.');

            return self::FAILURE;
        }

        $this->info('Provider usage matches internal records.');

        return self::SUCCESS;
    }
}
