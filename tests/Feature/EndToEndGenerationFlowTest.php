<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\FakesPixelforge;
use Tests\TestCase;

class EndToEndGenerationFlowTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
            'services.pixelforge.api_key' => 'pf_test_key',
            'services.pixelforge.webhook_secret' => 'whsec_test_secret',
        ]);

        Storage::fake('local');

        Http::fake([
            '*/v1/pricing' => Http::response($this->pricingPayload()),
            '*/v1/generations' => Http::response($this->queuedGenerationPayload(), 202),
            '*/images/*' => Http::response($this->fakePngBytes(), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    public function test_a_user_can_register_submit_and_receive_a_succeeded_generation(): void
    {
        $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $user = User::sole();
        $this->assertAuthenticatedAs($user);

        $this->post('/credits', ['amount' => 100])->assertRedirect();
        $this->assertSame('100.000000000', $user->fresh()->credit_balance);

        $this->postJson('/generations/estimate', [
            'prompt' => 'aaaa',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
        ])->assertOk()->assertJson([
            'estimated_cost_min' => '0.014000000',
            'estimated_cost_max' => '0.021825000',
        ]);

        $this->post('/generations', [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [],
        ])->assertRedirect(route('generations.index'));

        $generation = Generation::sole();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->provider_generation_id);
        $this->assertNotNull($generation->estimated_cost_min);
        $this->assertNull($generation->customer_charge);

        $this->get('/generations')->assertInertia(
            fn ($page) => $page
                ->component('Generations/Index')
                ->has('generations.data', 1)
                ->where('generations.data.0.status', Generation::STATUS_QUEUED)
                ->where('generations.data.0.customer_charge', null)
                ->where('generations.data.0.image_url', null)
        );

        $object = $this->succeededGenerationPayload([
            'metadata' => ['generation_id' => (string) $generation->id],
        ]);

        $this->postSignedWebhook($this->webhookEvent('generation.succeeded', $object))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('0.050750000', $generation->provider_cost);
        $this->assertSame('0.063437500', $generation->customer_charge);
        $this->assertTrue(Storage::disk('local')->exists("generations/{$generation->id}.png"));

        $this->get('/generations')->assertInertia(
            fn ($page) => $page
                ->where('generations.data.0.status', Generation::STATUS_SUCCEEDED)
                ->where('generations.data.0.customer_charge', '0.063437500')
                ->where('generations.data.0.image_url', route('generations.image', $generation))
        );
    }

    public function test_a_failed_generation_is_shown_as_failed_and_free(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ])->assertRedirect(route('generations.index'));

        $generation = Generation::sole();

        $object = $this->failedGenerationPayload([
            'metadata' => ['generation_id' => (string) $generation->id],
        ]);

        $this->postSignedWebhook($this->webhookEvent('generation.failed', $object))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertNull($generation->provider_cost);
        $this->assertNull($generation->customer_charge);

        $this->actingAs($user)->get('/generations')->assertInertia(
            fn ($page) => $page
                ->where('generations.data.0.status', Generation::STATUS_FAILED)
                ->where('generations.data.0.customer_charge', null)
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedWebhook(array $payload): TestResponse
    {
        [$body, $headers] = $this->signedWebhook($payload);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], $headers, $body);
    }
}
