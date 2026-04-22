<?php

namespace App\Modules\Uy3\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Uy3WebhookPost;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class Uy3WebhookPostController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $rawPayload = (string) $request->getContent();

        if (trim($rawPayload) === '') {
            return response()->json([
                'error'   => 'invalid_payload',
                'message' => 'Empty JSON payload.',
            ], 422);
        }

        try {
            json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json([
                'error'   => 'invalid_payload',
                'message' => 'Request body must be valid JSON.',
            ], 422);
        }

        $attributes = [
            'payload' => $rawPayload,
            'received_at' => now(),
        ];

        Uy3WebhookPost::create($attributes);

        return response()->json([
            'ok' => true,
        ]);
    }
}
