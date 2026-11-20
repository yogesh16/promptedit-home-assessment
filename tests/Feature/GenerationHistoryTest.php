<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/generations')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_their_generation_history(): void
    {
        $user = User::factory()->create();
        Generation::factory()->count(3)->for($user)->create();

        $response = $this->actingAs($user)->get('/generations');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('Generations/Index')
                ->has('generations.data', 3)
        );
    }

    public function test_users_do_not_see_other_users_generations(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Generation::factory()->count(2)->for($user)->create();
        Generation::factory()->count(5)->for($other)->create();

        $response = $this->actingAs($user)->get('/generations');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('Generations/Index')
                ->has('generations.data', 2)
                ->where('generations.data.0.id', $user->generations()->latest('id')->first()->id)
        );
    }

    public function test_history_exposes_the_image_url_but_not_internal_provider_data(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->succeeded()->for($user)->create([
            'image_path' => 'generations/test.png',
        ]);

        $response = $this->actingAs($user)->get('/generations');

        $response->assertInertia(
            fn ($page) => $page
                ->where('generations.data.0.image_url', route('generations.image', $generation))
                ->missing('generations.data.0.provider_generation_id')
                ->missing('generations.data.0.idempotency_key')
                ->missing('generations.data.0.provider_cost')
        );
    }
}
