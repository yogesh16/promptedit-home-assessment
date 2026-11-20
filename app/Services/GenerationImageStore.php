<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GenerationImageStore
{
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Download the signed provider image and store it locally before the URL expires.
     *
     * @throws RuntimeException
     */
    public function store(Generation $generation, ?string $url): bool
    {
        if ($generation->image_path !== null && Storage::disk('local')->exists($generation->image_path)) {
            return false;
        }

        if (! is_string($url) || $url === '') {
            return false;
        }

        try {
            $response = Http::timeout(60)->connectTimeout(10)->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not download the generated image: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException('Image download failed with HTTP '.$response->status().'.');
        }

        $body = $response->body();

        if (! str_starts_with($body, self::PNG_SIGNATURE)) {
            throw new RuntimeException('Downloaded image is not a valid PNG file.');
        }

        $path = "generations/{$generation->id}.png";

        if (! Storage::disk('local')->put($path, $body)) {
            throw new RuntimeException('Could not store the generated image.');
        }

        $generation->forceFill(['image_path' => $path])->save();

        return true;
    }

    public function path(Generation $generation): ?string
    {
        $path = $generation->image_path;

        if ($path !== null && Storage::disk('local')->exists($path)) {
            return $path;
        }

        return null;
    }
}
