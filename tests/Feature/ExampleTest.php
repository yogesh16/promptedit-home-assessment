<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_the_home_page_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_authenticated_users_are_redirected_from_the_home_page_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/dashboard');
    }
}
