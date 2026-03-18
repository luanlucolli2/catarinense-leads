<?php

namespace App\Modules\Uy3\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Uy3WebhookPost;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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

        if (Schema::hasColumn('uy3_webhook_posts', 'search_text')) {
            $attributes['search_text'] = $this->buildSearchText($rawPayload);
        }

        try {
            Uy3WebhookPost::create($attributes);
        } catch (QueryException $e) {
            // Compatibilidade temporária: banco sem migration da coluna search_text.
            if (array_key_exists('search_text', $attributes) && $this->isMissingSearchTextColumn($e)) {
                unset($attributes['search_text']);
                Uy3WebhookPost::create($attributes);
            } else {
                throw $e;
            }
        }

        return response()->json([
            'ok' => true,
        ]);
    }

    private function buildSearchText(string $rawPayload): string
    {
        $normalized = mb_strtolower($rawPayload, 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if (mb_strlen($normalized, 'UTF-8') > 12000) {
            $normalized = mb_substr($normalized, 0, 12000, 'UTF-8');
        }

        return $normalized;
    }

    private function isMissingSearchTextColumn(QueryException $e): bool
    {
        $message = mb_strtolower($e->getMessage(), 'UTF-8');
        return str_contains($message, 'unknown column')
            && str_contains($message, 'search_text');
    }
}
