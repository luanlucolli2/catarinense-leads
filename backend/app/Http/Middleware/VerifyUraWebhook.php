<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyUraWebhook
{
    /**
     * Verifica o segredo enviado pela URA (shared secret).
     *
     * Aceita:
     * - Header: X-Ura-Secret (ou variações)
     * - Header: Authorization: Bearer <secret>
     * - Query: ?token=<secret>
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('ura.webhook_secret');

        if (! is_string($configured) || $configured === '') {
            Log::critical('URA webhook secret not configured in .env or config/ura.php');

            return response()->json([
                'error'   => 'server_configuration_error',
                'message' => 'Internal server error.',
            ], 500);
        }

        $provided = $request->header('X-Ura-Secret')
            ?? $request->header('X-URA-Secret')
            ?? $request->header('X-Ura-Token')
            ?? $request->header('X-Ura-Auth')
            ?? $this->bearerFromAuthorizationHeader($request)
            ?? $request->query('token');

        if (! is_string($provided) || $provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json([
                'error'   => 'unauthorized',
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        return $next($request);
    }

    private function bearerFromAuthorizationHeader(Request $request): ?string
    {
        $auth = $request->header('Authorization');
        if (! is_string($auth) || $auth === '') {
            return null;
        }

        // "Bearer xxx"
        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $auth, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
