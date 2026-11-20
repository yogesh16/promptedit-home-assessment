<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreditService
{
    public function purchase(User $user, string $amount): CreditTransaction
    {
        $normalized = $this->positiveAmount($amount, 'The purchase amount must be greater than zero.');

        return DB::transaction(function () use ($user, $normalized): CreditTransaction {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $balance = bcadd((string) $locked->credit_balance, $normalized, 9);

            $locked->update(['credit_balance' => $balance]);

            return $locked->creditTransactions()->create([
                'type' => CreditTransaction::TYPE_PURCHASE,
                'amount' => $normalized,
                'balance_after' => $balance,
                'description' => 'Simulated credit purchase',
            ]);
        });
    }

    /**
     * Record the actual customer charge once a generation succeeds.
     */
    public function charge(Generation $generation): ?CreditTransaction
    {
        if ($generation->customer_charge === null) {
            return null;
        }

        $charge = $this->normalize((string) $generation->customer_charge);

        if (bccomp($charge, '0.000000000', 9) <= 0) {
            return null;
        }

        return DB::transaction(function () use ($generation, $charge): CreditTransaction {
            $existing = CreditTransaction::query()
                ->where('generation_id', $generation->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $user = User::query()->whereKey($generation->user_id)->lockForUpdate()->firstOrFail();
            $balance = bcsub((string) $user->credit_balance, $charge, 9);

            $user->update(['credit_balance' => $balance]);

            return $user->creditTransactions()->create([
                'generation_id' => $generation->id,
                'type' => CreditTransaction::TYPE_CHARGE,
                'amount' => $charge,
                'balance_after' => $balance,
                'description' => "Generation #{$generation->id}",
            ]);
        });
    }

    public function hasSufficientBalance(User $user, string $required): bool
    {
        $balance = (string) User::query()->whereKey($user->id)->value('credit_balance');

        return bccomp($balance, $this->normalize($required), 9) >= 0;
    }

    public function balance(User $user): string
    {
        return (string) User::query()->whereKey($user->id)->value('credit_balance');
    }

    private function positiveAmount(string $amount, string $message): string
    {
        $normalized = $this->normalize($amount);

        if (bccomp($normalized, '0.000000000', 9) <= 0) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function normalize(string $amount): string
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('The amount must be numeric.');
        }

        return bcadd($amount, '0', 9);
    }
}
