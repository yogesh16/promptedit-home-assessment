<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    protected $model = Generation::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => null,
            'status' => Generation::STATUS_QUEUED,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => Generation::STATUS_SUCCEEDED,
            'provider_generation_id' => 'gen_'.Str::upper(Str::random(24)),
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'usage' => [
                'input_text_tokens' => 100,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 500,
                'total_tokens' => 1700,
            ],
            'provider_cost' => '0.050750000',
            'customer_charge' => '0.063437500',
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => Generation::STATUS_FAILED,
            'provider_generation_id' => 'gen_'.Str::upper(Str::random(24)),
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image for the prompt.',
            'completed_at' => now(),
        ]);
    }
}
