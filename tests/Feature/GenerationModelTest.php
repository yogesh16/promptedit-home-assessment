<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_generation_can_be_created_with_valid_defaults(): void
    {
        $user = User::factory()->create();

        $generation = Generation::factory()->for($user)->create();

        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertSame('1024x1024', $generation->size);
        $this->assertSame('medium', $generation->quality);
        $this->assertNotNull($generation->idempotency_key);
        $this->assertNull($generation->provider_generation_id);
        $this->assertTrue($generation->user->is($user));
    }

    public function test_idempotency_key_must_be_unique(): void
    {
        $generation = Generation::factory()->create();

        $this->expectException(QueryException::class);

        Generation::factory()->create([
            'idempotency_key' => $generation->idempotency_key,
        ]);
    }

    public function test_provider_generation_id_must_be_unique_when_present(): void
    {
        $first = Generation::factory()->succeeded()->create();
        $second = Generation::factory()->create();

        $this->expectException(QueryException::class);

        $second->update([
            'provider_generation_id' => $first->provider_generation_id,
        ]);
    }

    public function test_money_and_array_fields_are_cast_precisely(): void
    {
        $generation = Generation::factory()->succeeded()->create([
            'estimated_cost_min' => '0.037500000',
            'estimated_cost_max' => '0.043125000',
            'reference_images' => ['https://example.com/a.png'],
        ]);

        $fresh = $generation->fresh();

        $this->assertSame('0.050750000', $fresh->provider_cost);
        $this->assertSame('0.063437500', $fresh->customer_charge);
        $this->assertSame('0.037500000', $fresh->estimated_cost_min);
        $this->assertSame('0.043125000', $fresh->estimated_cost_max);
        $this->assertSame(['https://example.com/a.png'], $fresh->reference_images);
        $this->assertIsArray($fresh->rates);
        $this->assertIsArray($fresh->usage);
    }

    public function test_terminal_statuses_are_detected(): void
    {
        $queued = Generation::factory()->create();
        $succeeded = Generation::factory()->succeeded()->create();
        $failed = Generation::factory()->failed()->create();

        $this->assertFalse($queued->isTerminal());
        $this->assertTrue($succeeded->isTerminal());
        $this->assertTrue($failed->isTerminal());
    }

    public function test_failed_generations_have_no_charge(): void
    {
        $failed = Generation::factory()->failed()->create();

        $this->assertNull($failed->provider_cost);
        $this->assertNull($failed->customer_charge);
    }
}
