<?php

namespace App\Modules\Uy3\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Uy3\Support\Uy3WebhookPayloadNormalizer;
use App\Models\Uy3WebhookPost;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json([
                'error'   => 'invalid_payload',
                'message' => 'Request body must be valid JSON.',
            ], 422);
        }

        try {
            $payload = Uy3WebhookPayloadNormalizer::normalize($payload);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'Invalid webhook payload.',
                'errors' => $e->errors(),
            ], 422);
        }

        $attributes = [
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'received_at' => now(),
        ];

        Uy3WebhookPost::create($attributes);

        return response()->json([
            'ok' => true,
        ]);
    }
}
