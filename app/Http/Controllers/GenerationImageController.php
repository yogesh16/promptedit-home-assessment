<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\GenerationImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GenerationImageController extends Controller
{
    public function __invoke(Request $request, Generation $generation, GenerationImageStore $images): StreamedResponse
    {
        abort_unless($request->user()?->id === $generation->user_id, 404);

        $path = $images->path($generation);

        abort_if($path === null, 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
