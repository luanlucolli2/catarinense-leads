<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        // Evita materializar $request->all()
        $tokenOrigin = (string) (
            $request->input('token_origin')
            ?: $request->input('body.token_origin')
            ?: $request->input('ticketData.whatsapp.token')
            ?: ''
        );

        if ($tokenOrigin === '' || ! $this->isAllowed($tokenOrigin, $allowed)) {
            // Anti-spam de log (ataque/bot)
            $cooldown = (int) config('inovachat.queue_webhook.unauthorized_log_cooldown_seconds', 60);
            $cooldown = max(10, $cooldown);

            $logFailures = (bool) config('inovachat.logging.log_failures', true);

            if ($logFailures) {
                $key = 'inovachat:queuewebhook:unauthlog:' . sha1(($request->ip() ?: '-') . '|' . ($tokenOrigin !== '' ? substr($tokenOrigin, 0, 12) : 'empty'));
                if (Cache::add($key, 1, $cooldown)) {
                    Log::warning('Invalid inovachat queue webhook token_origin', [
                        'expected_count' => count($allowed),
                        'received'       => $tokenOrigin !== '' ? (substr($tokenOrigin, 0, 6) . '***') : '(empty)',
                        'ip'             => $request->ip(),
                    ]);
                }
            }

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
