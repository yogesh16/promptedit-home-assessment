<?php

namespace App\Console\Commands;

use App\Exceptions\PixelforgeApiException;
use App\Models\Generation;
use App\Services\GenerationImageStore;
use App\Services\GenerationProcessor;
use App\Services\PixelforgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecoverGenerations extends Command
{
    protected $signature = 'generations:recover';

    protected $description = 'Poll Pixelforge for non-terminal generations and retry missing image downloads.';

    public function handle(
        PixelforgeClient $client,
        GenerationProcessor $processor,
        GenerationImageStore $images,
    ): int {
        $this->recoverStatuses($client, $processor, $images);
        $this->recoverImages($client, $images);

        return self::SUCCESS;
    }

    private function recoverStatuses(
        PixelforgeClient $client,
        GenerationProcessor $processor,
        GenerationImageStore $images,
    ): void {
        Generation::query()
            ->whereIn('status', [Generation::STATUS_QUEUED, Generation::STATUS_PROCESSING])
            ->whereNotNull('provider_generation_id')
            ->where('updated_at', '<', now()->subSeconds(30))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function (Generation $generation) use ($client, $processor, $images): void {
                try {
                    $object = $client->getGeneration($generation->provider_generation_id);
                    $fresh = $processor->process($object);

                    if (
                        $fresh !== null
                        && $fresh->status === Generation::STATUS_SUCCEEDED
                        && $fresh->image_path === null
                    ) {
                        $images->store($fresh, $object['image_url'] ?? null);
                    }
                } catch (PixelforgeApiException $e) {
                    Log::warning('Could not poll Pixelforge generation.', [
                        'generation_id' => $generation->id,
                        'error_code' => $e->errorCode,
                        'retryable' => $e->retryable,
                    ]);
                } catch (Throwable $e) {
                    Log::error('Unexpected error while recovering a Pixelforge generation.', [
                        'generation_id' => $generation->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            });
    }

    private function recoverImages(PixelforgeClient $client, GenerationImageStore $images): void
    {
        Generation::query()
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->whereNull('image_path')
            ->whereNotNull('provider_generation_id')
            ->where('completed_at', '>', now()->subHours(23))
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->each(function (Generation $generation) use ($client, $images): void {
                try {
                    $object = $client->getGeneration($generation->provider_generation_id);
                    $images->store($generation, $object['image_url'] ?? null);
                } catch (Throwable $e) {
                    Log::warning('Could not recover a generated image.', [
                        'generation_id' => $generation->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            });
    }
}
