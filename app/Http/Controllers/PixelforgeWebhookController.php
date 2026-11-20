<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPixelforgeWebhookJob;
use App\Models\WebhookEvent;
use App\Services\PixelforgeWebhookVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, PixelforgeWebhookVerifier $verifier): Response
    {
        $payload = $request->getContent();

        if (! $verifier->verify($request, $payload)) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        $data = json_decode($payload, true);

        if (
            ! is_array($data)
            || ! is_string($data['id'] ?? null)
            || ! is_string($data['type'] ?? null)
        ) {
            abort(400, 'Invalid Pixelforge payload.');
        }

        if ($data['type'] === 'ping') {
            return response()->noContent();
        }

        try {
            $event = WebhookEvent::firstOrCreate(
                ['provider_event_id' => $data['id']],
                [
                    'event_type' => $data['type'],
                    'payload' => $data,
                    'status' => WebhookEvent::STATUS_RECEIVED,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return response()->noContent();
        }

        if ($event->wasRecentlyCreated) {
            ProcessPixelforgeWebhookJob::dispatch($event->id);
        }

        return response()->noContent();
    }
}
