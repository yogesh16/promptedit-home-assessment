<?php

namespace Tests\Feature;

use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\FakesPixelforge;
use Tests\TestCase;

class GenerationRecoveryTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
            'services.pixelforge.api_key' => 'pf_test_key',
        ]);

        Storage::fake('local');
    }

    public function test_missed_webhook_is_recovered_by_polling(): void
    {
        $generation = $this->staleGeneration(['status' => Generation::STATUS_PROCESSING]);

        Http::fake([
            '*/v1/generations/gen_recover_1' => Http::response($this->succeededGenerationPayload(['id' => 'gen_recover_1'])),
            '*/images/*' => Http::response($this->fakePngBytes(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->artisan('generations:recover')->assertSuccessful();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('0.050750000', $generation->provider_cost);
        $this->assertSame('0.063437500', $generation->customer_charge);
        $this->assertSame("generations/{$generation->id}.png", $generation->image_path);
    }

    public function test_polling_failure_marks_the_generation_failed_and_free(): void
    {
        $generation = $this->staleGeneration(['status' => Generation::STATUS_PROCESSING]);

        Http::fake([
            '*/v1/generations/gen_recover_1' => Http::response($this->failedGenerationPayload(['id' => 'gen_recover_1'])),
        ]);

        $this->artisan('generations:recover')->assertSuccessful();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertNull($generation->customer_charge);
    }

    public function test_polling_skips_terminal_generations(): void
    {
        $generation = Generation::factory()->succeeded()->create([
            'updated_at' => now()->subMinute(),
            'image_path' => 'generations/existing.png',
        ]);

        Http::fake();

        $this->artisan('generations:recover')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->fresh()->status);
    }

    public function test_recently_updated_generations_are_not_polled_again(): void
    {
        Generation::factory()->create([
            'status' => Generation::STATUS_PROCESSING,
            'provider_generation_id' => 'gen_recent',
            'updated_at' => now(),
        ]);

        Http::fake();

        $this->artisan('generations:recover')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_missing_image_for_a_succeeded_generation_is_retried(): void
    {
        $generation = Generation::factory()->create([
            'status' => Generation::STATUS_SUCCEEDED,
            'provider_generation_id' => 'gen_recover_1',
            'provider_cost' => '0.050750000',
            'customer_charge' => '0.063437500',
            'completed_at' => now()->subMinute(),
            'image_path' => null,
        ]);

        $payload = $this->succeededGenerationPayload(['id' => 'gen_recover_1']);

        Http::fake([
            '*/v1/generations/gen_recover_1' => Http::response($payload),
            '*/images/*' => Http::response($this->fakePngBytes(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->artisan('generations:recover')->assertSuccessful();

        $generation->refresh();
        $this->assertSame("generations/{$generation->id}.png", $generation->image_path);
        $this->assertTrue(Storage::disk('local')->exists($generation->image_path));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function staleGeneration(array $attributes): Generation
    {
        return Generation::factory()->create(array_merge([
            'provider_generation_id' => 'gen_recover_1',
            'updated_at' => now()->subMinute(),
        ], $attributes));
    }
}
