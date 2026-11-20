<?php

namespace App\Services;

use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class GenerationProcessor
{
    public function __construct(private readonly FinancialService $financial) {}

    /**
     * Apply a Pixelforge generation object to the correlated local generation.
     *
     * Returns null when the generation cannot be correlated yet (for example an
     * early webhook that raced ahead of the submission response).
     *
     * @param  array<string, mixed>  $object
     *
     * @throws UnexpectedValueException
     */
    public function process(array $object): ?Generation
    {
        $providerId = $object['id'] ?? null;

        if (! is_string($providerId) || $providerId === '') {
            throw new UnexpectedValueException('Generation object is missing its provider id.');
        }

        return DB::transaction(function () use ($object, $providerId): ?Generation {
            $generation = Generation::query()
                ->where('provider_generation_id', $providerId)
                ->lockForUpdate()
                ->first();

            if ($generation === null) {
                $generation = $this->findByMetadata($object);
            }

            if ($generation === null) {
                return null;
            }

            if ($generation->provider_generation_id === null) {
                $generation->provider_generation_id = $providerId;
            } elseif ($generation->provider_generation_id !== $providerId) {
                return null;
            }

            $this->applyStatus($generation, $object);
            $generation->save();

            return $generation->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function findByMetadata(array $object): ?Generation
    {
        $internalId = $object['metadata']['generation_id'] ?? null;

        if (! is_string($internalId) && ! is_int($internalId)) {
            return null;
        }

        return Generation::query()
            ->whereKey((int) $internalId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function applyStatus(Generation $generation, array $object): void
    {
        $status = $object['status'] ?? null;
        $recoveringUncertain = $generation->status === Generation::STATUS_FAILED
            && $generation->error_code === 'submission_uncertain';

        if ($status === Generation::STATUS_PROCESSING) {
            if ($generation->status !== Generation::STATUS_QUEUED && ! $recoveringUncertain) {
                return;
            }

            $generation->status = Generation::STATUS_PROCESSING;
            $generation->error_code = null;
            $generation->error_message = null;

            return;
        }

        if ($status === Generation::STATUS_SUCCEEDED) {
            if ($generation->status === Generation::STATUS_SUCCEEDED && $generation->customer_charge !== null) {
                return;
            }

            if ($generation->status === Generation::STATUS_FAILED && ! $recoveringUncertain) {
                return;
            }

            $usage = $object['usage'] ?? null;
            $rates = $object['rates'] ?? $generation->rates;

            if (! is_array($usage) || $usage === []) {
                throw new UnexpectedValueException('Succeeded generation is missing usage.');
            }

            if (! is_array($rates) || $rates === []) {
                throw new UnexpectedValueException('Succeeded generation is missing rates.');
            }

            $providerCost = $this->financial->providerCost($usage, $rates);

            $generation->status = Generation::STATUS_SUCCEEDED;
            $generation->rates = $rates;
            $generation->usage = $usage;
            $generation->provider_cost = $providerCost;
            $generation->customer_charge = $this->financial->customerCharge($providerCost);
            $generation->error_code = null;
            $generation->error_message = null;
            $generation->completed_at = $this->timestamp($object['completed_at'] ?? null) ?? now();
            $generation->image_url_expires_at = $this->timestamp($object['image_url_expires_at'] ?? null);

            return;
        }

        if ($status === Generation::STATUS_FAILED) {
            if ($generation->status === Generation::STATUS_SUCCEEDED) {
                return;
            }

            if ($generation->status === Generation::STATUS_FAILED && ! $recoveringUncertain) {
                return;
            }

            $error = $object['error'] ?? [];

            $generation->status = Generation::STATUS_FAILED;
            $generation->error_code = is_string($error['code'] ?? null) ? $error['code'] : 'generation_failed';
            $generation->error_message = is_string($error['message'] ?? null)
                ? $error['message']
                : 'The generation failed.';
            $generation->provider_cost = null;
            $generation->customer_charge = null;
            $generation->completed_at = $this->timestamp($object['completed_at'] ?? null) ?? now();
        }
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
