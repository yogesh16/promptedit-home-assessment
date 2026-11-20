<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Services\GenerationImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\FakesPixelforge;
use Tests\TestCase;

class GenerationImageStoreTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_valid_png_is_stored(): void
    {
        $generation = Generation::factory()->succeeded()->create();

        Http::fake(['*/images/*' => Http::response($this->fakePngBytes(), 200, ['Content-Type' => 'image/png'])]);

        $stored = app(GenerationImageStore::class)->store($generation, 'https://pixelforge.test/images/gen.png');

        $this->assertTrue($stored);
        $this->assertSame("generations/{$generation->id}.png", $generation->fresh()->image_path);
        Storage::disk('local')->assertExists("generations/{$generation->id}.png");
    }

    public function test_a_non_png_download_is_rejected_and_not_stored(): void
    {
        $generation = Generation::factory()->succeeded()->create();

        Http::fake(['*/images/*' => Http::response('GIF89a not a png', 200, ['Content-Type' => 'image/gif'])]);

        try {
            app(GenerationImageStore::class)->store($generation, 'https://pixelforge.test/images/gen.png');
            $this->fail('Expected the image store to reject non-PNG content.');
        } catch (RuntimeException) {
        }

        $this->assertNull($generation->fresh()->image_path);
        Storage::disk('local')->assertMissing("generations/{$generation->id}.png");
    }

    public function test_a_failed_download_is_rejected_and_not_stored(): void
    {
        $generation = Generation::factory()->succeeded()->create();

        Http::fake(['*/images/*' => Http::response('upstream error', 500)]);

        try {
            app(GenerationImageStore::class)->store($generation, 'https://pixelforge.test/images/gen.png');
            $this->fail('Expected the image store to reject the failed download.');
        } catch (RuntimeException) {
        }

        $this->assertNull($generation->fresh()->image_path);
        Storage::disk('local')->assertMissing("generations/{$generation->id}.png");
    }

    public function test_an_existing_image_is_not_downloaded_again(): void
    {
        $generation = Generation::factory()->succeeded()->create([
            'image_path' => 'generations/existing.png',
        ]);

        Storage::disk('local')->put('generations/existing.png', $this->fakePngBytes());
        Http::fake();

        $stored = app(GenerationImageStore::class)->store($generation, 'https://pixelforge.test/images/gen.png');

        $this->assertFalse($stored);
        Http::assertNothingSent();
    }
}
