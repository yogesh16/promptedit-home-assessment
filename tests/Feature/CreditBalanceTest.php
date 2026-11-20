<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\FakesPixelforge;
use Tests\TestCase;

class CreditBalanceTest extends TestCase
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

    public function test_simulated_purchase_increases_the_balance_and_records_a_transaction(): void
    {
        $user = User::factory()->creditBalance('0.000000000')->create();

        $this->actingAs($user)->post('/credits', ['amount' => 25])->assertRedirect();

        $user->refresh();
        $this->assertSame('25.000000000', $user->credit_balance);

        $transaction = CreditTransaction::sole();
        $this->assertSame(CreditTransaction::TYPE_PURCHASE, $transaction->type);
        $this->assertSame('25.000000000', $transaction->amount);
        $this->assertSame('25.000000000', $transaction->balance_after);
        $this->assertSame($user->id, $transaction->user_id);
    }

    public function test_invalid_purchase_amount_is_rejected(): void
    {
        $user = User::factory()->creditBalance('0.000000000')->create();

        $this->actingAs($user)->post('/credits', ['amount' => 0])->assertSessionHasErrors('amount');
        $this->actingAs($user)->post('/credits', ['amount' => 1001])->assertSessionHasErrors('amount');

        $this->assertSame('0.000000000', $user->fresh()->credit_balance);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_generation_submission_is_blocked_when_credits_are_insufficient(): void
    {
        $user = User::factory()->creditBalance('0.001000000')->create();

        $this->actingAs($user)->post('/generations', $this->generationPayload())
            ->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::count());
    }

    public function test_generation_submission_is_allowed_with_sufficient_credits(): void
    {
        $user = User::factory()->creditBalance('1.000000000')->create();

        $this->actingAs($user)->post('/generations', $this->generationPayload())
            ->assertRedirect(route('generations.index'));

        $this->assertSame(1, Generation::count());
    }

    public function test_successful_generation_deducts_the_actual_customer_charge(): void
    {
        $user = User::factory()->creditBalance('100.000000000')->create();
        $generation = Generation::factory()->for($user)->create([
            'provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        ]);

        $object = $this->succeededGenerationPayload([
            'metadata' => ['generation_id' => (string) $generation->id],
        ]);

        $this->postSignedWebhook($this->webhookEvent('generation.succeeded', $object))->assertNoContent();

        $user->refresh();
        $this->assertSame('99.936562500', $user->credit_balance);

        $transaction = CreditTransaction::where('type', CreditTransaction::TYPE_CHARGE)->sole();
        $this->assertSame($generation->id, $transaction->generation_id);
        $this->assertSame('0.063437500', $transaction->amount);
        $this->assertSame('99.936562500', $transaction->balance_after);
    }

    public function test_failed_generation_does_not_deduct_credits(): void
    {
        $user = User::factory()->creditBalance('100.000000000')->create();
        $generation = Generation::factory()->for($user)->create([
            'provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        ]);

        $object = $this->failedGenerationPayload([
            'metadata' => ['generation_id' => (string) $generation->id],
        ]);

        $this->postSignedWebhook($this->webhookEvent('generation.failed', $object))->assertNoContent();

        $this->assertSame('100.000000000', $user->fresh()->credit_balance);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_duplicate_success_webhooks_charge_only_once(): void
    {
        $user = User::factory()->creditBalance('100.000000000')->create();
        $generation = Generation::factory()->for($user)->create([
            'provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        ]);

        $object = $this->succeededGenerationPayload([
            'metadata' => ['generation_id' => (string) $generation->id],
        ]);

        $this->postSignedWebhook($this->webhookEvent('generation.succeeded', $object, 'evt_first'))->assertNoContent();
        $this->postSignedWebhook($this->webhookEvent('generation.succeeded', $object, 'evt_second'))->assertNoContent();

        $this->assertSame('99.936562500', $user->fresh()->credit_balance);
        $this->assertSame(1, CreditTransaction::where('type', CreditTransaction::TYPE_CHARGE)->count());
    }

    public function test_credits_page_lists_only_the_users_own_transactions(): void
    {
        $user = User::factory()->creditBalance('10.000000000')->create();
        $other = User::factory()->creditBalance('20.000000000')->create();

        app(CreditService::class)->purchase($user, '5');
        app(CreditService::class)->purchase($other, '7');

        $this->actingAs($user)->get('/credits')->assertInertia(
            fn ($page) => $page
                ->component('Credits/Index')
                ->where('balance', '15.000000000')
                ->has('transactions.data', 1)
                ->where('transactions.data.0.amount', '5.000000000')
        );
    }

    public function test_guests_are_redirected_from_credits_to_login(): void
    {
        $this->get('/credits')->assertRedirect('/login');
    }

    /**
     * @return array<string, mixed>
     */
    private function generationPayload(): array
    {
        return [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [],
        ];
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
