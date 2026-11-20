<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PixelforgeApiException;
use App\Http\Controllers\Controller;
use App\Services\FinancialReportService;
use App\Services\ProviderUsageReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ReportController extends Controller
{
    public function index(
        Request $request,
        FinancialReportService $reports,
        ProviderUsageReconciler $reconciler,
    ): Response {
        $data = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d'],
            'reconcile' => ['nullable', 'boolean'],
        ]);

        $end = CarbonImmutable::parse($data['end'] ?? CarbonImmutable::now('UTC')->toDateString(), 'UTC')->startOfDay();
        $start = isset($data['start'])
            ? CarbonImmutable::parse($data['start'], 'UTC')->startOfDay()
            : $end->subDays(29);

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages([
                'start' => 'The start date must not be after the end date.',
            ]);
        }

        if ((int) $start->diffInDays($end) + 1 > 92) {
            throw ValidationException::withMessages([
                'start' => 'The reporting range must not exceed 92 days.',
            ]);
        }

        $daily = $reports->daily($start, $end);
        $perUser = $reports->perUser($start, $end);

        $reconciliation = null;
        $reconciliationError = null;

        if ($request->boolean('reconcile')) {
            try {
                $reconciliation = $reconciler->reconcile($start, $end);
            } catch (InvalidArgumentException $e) {
                $reconciliationError = $e->getMessage();
            } catch (PixelforgeApiException $e) {
                $reconciliationError = 'Could not retrieve Pixelforge usage: '.$e->getMessage();
            }
        }

        return Inertia::render('Admin/Reports', [
            'filters' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'reconciled' => $request->boolean('reconcile'),
            ],
            'daily' => $daily,
            'perUser' => $perUser,
            'reconciliation' => $reconciliation,
            'reconciliationError' => $reconciliationError,
        ]);
    }
}
