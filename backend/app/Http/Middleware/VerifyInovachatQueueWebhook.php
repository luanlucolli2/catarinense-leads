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
        $configured = (string) config('inovachat.queue_webhook.token_origin');

        if ($configured === '') {
            Log::critical('Inovachat queue webhook token_origin not configured');
            return response()->json([
                'error' => 'server_configuration_error',
                'message' => 'Internal server error.',
            ], 500);
        }

        $payload = $request->all();

        // Suporta múltiplos formatos de payload:
        // - token_origin no topo (seu caso atual)
        // - body.token_origin (alguns exemplos de doc)
        // - ticketData.whatsapp.token (também aparece no payload que você enviou)
        $tokenOrigin = (string) (
            data_get($payload, 'token_origin')
            ?: data_get($payload, 'body.token_origin')
            ?: data_get($payload, 'ticketData.whatsapp.token')
        );

        if ($tokenOrigin === '' || ! hash_equals($configured, $tokenOrigin)) {
            Log::warning('Invalid inovachat queue webhook token_origin', [
                'expected' => substr($configured, 0, 6) . '***',
                'received' => $tokenOrigin !== '' ? (substr($tokenOrigin, 0, 6) . '***') : '(empty)',
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Invalid webhook token_origin.',
            ], 401);
        }

        return $next($request);
    }
}
