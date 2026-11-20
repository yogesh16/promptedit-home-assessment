<?php

namespace App\Http\Controllers;

use App\Exceptions\PixelforgeApiException;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        $generations = $request->user()
            ->generations()
            ->latest('id')
            ->paginate(10);

        return Inertia::render('Generations/Index', [
            'generations' => $generations,
        ]);
    }

    public function store(StoreGenerationRequest $request, PricingService $pricing): RedirectResponse
    {
        $data = $request->validated();
        $referenceImages = array_values($data['reference_images'] ?? []);
        $estimate = $this->estimatedCost($pricing, $data, $referenceImages);

        $generation = $request->user()->generations()->create([
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_images' => $referenceImages === [] ? null : $referenceImages,
            'status' => Generation::STATUS_QUEUED,
            'idempotency_key' => (string) Str::uuid(),
            'estimated_cost_min' => $estimate['estimated_cost_min'],
            'estimated_cost_max' => $estimate['estimated_cost_max'],
        ]);

        SubmitGenerationJob::dispatch($generation->id);

        return redirect()
            ->route('generations.index')
            ->with('success', 'Generation queued.');
    }

    public function estimate(StoreGenerationRequest $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validated();
        $estimate = $this->estimatedCost(
            $pricing,
            $data,
            array_values($data['reference_images'] ?? []),
        );

        return response()->json([
            'estimated_cost_min' => $estimate['estimated_cost_min'],
            'estimated_cost_max' => $estimate['estimated_cost_max'],
            'currency' => 'usd',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $referenceImages
     * @return array{estimated_cost_min: string, estimated_cost_max: string}
     *
     * @throws ValidationException
     */
    private function estimatedCost(PricingService $pricing, array $data, array $referenceImages): array
    {
        try {
            return $pricing->estimate($data['prompt'], $data['size'], $data['quality'], $referenceImages);
        } catch (PixelforgeApiException) {
            throw ValidationException::withMessages([
                'pricing' => 'Cost estimation is temporarily unavailable. Please try again.',
            ]);
        }
    }
}
