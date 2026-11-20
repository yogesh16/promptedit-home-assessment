<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\CreditService;
use App\Services\FinancialReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        CreditService $credits,
        FinancialReportService $reports,
    ): Response {
        $user = $request->user();

        $counts = $user->generations()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn (string $status): int => (int) ($counts[$status] ?? 0);

        $totalSpent = $user->generations()
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->pluck('customer_charge')
            ->reduce(fn (string $carry, mixed $value): string => bcadd($carry, (string) $value, 9), '0.000000000');

        $succeeded = $count(Generation::STATUS_SUCCEEDED);
        $failed = $count(Generation::STATUS_FAILED);
        $terminal = $succeeded + $failed;

        $recent = $user->generations()
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (Generation $generation): array => [
                'id' => $generation->id,
                'prompt' => $generation->prompt,
                'status' => $generation->status,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'customer_charge' => $generation->customer_charge,
                'image_url' => $generation->image_path !== null
                    ? route('generations.image', $generation)
                    : null,
                'created_at' => $generation->created_at?->toIso8601String(),
            ]);

        $adminSummary = null;

        if ($user->is_admin) {
            $today = CarbonImmutable::now('UTC')->startOfDay();
            $adminSummary = $reports->daily($today, $today)['totals'];
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'credits' => $credits->balance($user),
                'total_generations' => (int) $counts->sum(),
                'queued' => $count(Generation::STATUS_QUEUED),
                'processing' => $count(Generation::STATUS_PROCESSING),
                'succeeded' => $succeeded,
                'failed' => $failed,
                'active' => $count(Generation::STATUS_QUEUED) + $count(Generation::STATUS_PROCESSING),
                'total_spent' => $totalSpent,
                'success_rate' => $terminal > 0 ? (int) round($succeeded / $terminal * 100) : null,
            ],
            'recent' => $recent,
            'adminSummary' => $adminSummary,
        ]);
    }
}
