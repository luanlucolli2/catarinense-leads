<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyInovachatQueueWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $explicit = config('inovachat.queue_webhook.token_origins');
        $explicit = is_array($explicit) ? $explicit : [];

        $fallback = config('inovachat.connections.tokens');
        $fallback = is_array($fallback) ? $fallback : [];

        $allowed = array_values(array_filter(array_map('trim', array_merge($explicit, $fallback))));
        $allowed = array_values(array_unique($allowed));

        if (empty($allowed)) {
            Log::critical('Inovachat queue webhook token origins not configured');
            return response()->json([
                'error' => 'server_configuration_error',
                'message' => 'Internal server error.',
            ], 500);
        }

        $payload = $request->all();

        // Suporta múltiplos formatos de payload:
        // - token_origin no topo
        // - body.token_origin (doc)
        // - ticketData.whatsapp.token (alguns payloads)
        $tokenOrigin = (string) (
            data_get($payload, 'token_origin')
            ?: data_get($payload, 'body.token_origin')
            ?: data_get($payload, 'ticketData.whatsapp.token')
        );

        if ($tokenOrigin === '' || ! $this->isAllowed($tokenOrigin, $allowed)) {
            Log::warning('Invalid inovachat queue webhook token_origin', [
                'expected_count' => count($allowed),
                'received' => $tokenOrigin !== '' ? (substr($tokenOrigin, 0, 6) . '***') : '(empty)',
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Invalid webhook token_origin.',
            ], 401);
        }

        // Disponibiliza o token da conexão para o controller
        $request->attributes->set('inovachat_connection_token', $tokenOrigin);

        return $next($request);
    }

    private function isAllowed(string $provided, array $allowed): bool
    {
        foreach ($allowed as $expected) {
            if (is_string($expected) && $expected !== '' && hash_equals($expected, $provided)) {
                return true;
            }
        }
        return false;
    }
}
