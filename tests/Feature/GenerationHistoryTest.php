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
                ->where('generations.data.0.user_id', $user->id)
        );
    }
}
