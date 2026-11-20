<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Services\CreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public function index(Request $request, CreditService $credits): Response
    {
        $transactions = $request->user()
            ->creditTransactions()
            ->latest('id')
            ->paginate(15)
            ->through(fn (CreditTransaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
                'balance_after' => $transaction->balance_after,
                'description' => $transaction->description,
                'created_at' => $transaction->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Credits/Index', [
            'balance' => $credits->balance($request->user()),
            'transactions' => $transactions,
        ]);
    }

    public function store(Request $request, CreditService $credits): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:1000'],
        ]);

        $credits->purchase($request->user(), (string) $data['amount']);

        return back()->with('success', 'Credits added to your balance.');
    }
}
