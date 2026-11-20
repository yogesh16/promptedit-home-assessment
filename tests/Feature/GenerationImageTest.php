<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\FakesPixelforge;
use Tests\TestCase;

class GenerationImageTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_owner_can_view_the_stored_image(): void
    {
        $generation = Generation::factory()->succeeded()->create([
            'image_path' => 'generations/test.png',
        ]);

        Storage::disk('local')->put('generations/test.png', $this->fakePngBytes());

        $this->actingAs($generation->user)
            ->get("/generations/{$generation->id}/image")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_other_users_cannot_view_the_image(): void
    {
        $generation = Generation::factory()->succeeded()->create([
            'image_path' => 'generations/test.png',
        ]);

        Storage::disk('local')->put('generations/test.png', $this->fakePngBytes());

        $this->actingAs(User::factory()->create())
            ->get("/generations/{$generation->id}/image")
            ->assertNotFound();
    }

    public function test_missing_image_returns_not_found(): void
    {
        $generation = Generation::factory()->create(['image_path' => null]);

        $this->actingAs($generation->user)
            ->get("/generations/{$generation->id}/image")
            ->assertNotFound();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $generation = Generation::factory()->create();

        $this->get("/generations/{$generation->id}/image")->assertRedirect('/login');
    }
}
