<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_see_their_generation_summary(): void
    {
        $user = User::factory()->creditBalance('42.500000000')->create();

        Generation::factory()->for($user)->succeeded()->create([
            'provider_cost' => '0.050750000',
            'customer_charge' => '0.063437500',
        ]);

        Generation::factory()->for($user)->failed()->create();

        Generation::factory()->for($user)->create([
            'status' => Generation::STATUS_PROCESSING,
            'provider_generation_id' => 'gen_dashboard_1',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Dashboard')
                    ->where('stats.credits', '42.500000000')
                    ->where('stats.total_generations', 3)
                    ->where('stats.succeeded', 1)
                    ->where('stats.failed', 1)
                    ->where('stats.processing', 1)
                    ->where('stats.active', 1)
                    ->where('stats.total_spent', '0.063437500')
                    ->where('stats.success_rate', 50)
                    ->has('recent', 3)
                    ->where('adminSummary', null)
            );
    }

    public function test_admins_also_see_todays_financial_summary(): void
    {
        $admin = User::factory()->admin()->create();

        Generation::factory()->succeeded()->create([
            'provider_cost' => '0.050750000',
            'customer_charge' => '0.063437500',
            'completed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('adminSummary.generations', 1)
                    ->where('adminSummary.provider_cost', '0.050750000')
                    ->where('adminSummary.customer_charge', '0.063437500')
                    ->where('adminSummary.profit', '0.012687500')
            );
    }

    public function test_the_dashboard_only_counts_the_users_own_generations(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Generation::factory()->count(2)->for($user)->create();
        Generation::factory()->count(5)->for($other)->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(
                fn ($page) => $page
                    ->where('stats.total_generations', 2)
                    ->has('recent', 2)
            );
    }
}
