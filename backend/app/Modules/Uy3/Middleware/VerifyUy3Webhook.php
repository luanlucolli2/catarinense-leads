<?php

namespace App\Modules\Uy3\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyUy3Webhook
{
    /**
     * Verifica o segredo enviado pelo parceiro UY3.
     *
     * Aceita:
     * - Header: Secret-Key
     * - Header: X-Secret-Key
     * - Header: X-UY3-Secret-Key (ou variação de casing)
     * - Header: Authorization: Bearer <secret>
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('uy3.webhook_secret');

        if (! is_string($configured) || $configured === '') {
            Log::critical('UY3 webhook secret not configured in .env or config/uy3.php');

            return response()->json([
                'error'   => 'server_configuration_error',
                'message' => 'Internal server error.',
            ], 500);
        }

        $provided = $this->extractProvidedSecret($request);

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

        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $auth, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractProvidedSecret(Request $request): ?string
    {
        $headers = [
            'X-UY3-Secret-Key',
            'X-Secret-Key',
            'Secret-Key',
        ];

        foreach ($headers as $header) {
            $value = $request->header($header);
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return $this->bearerFromAuthorizationHeader($request);
    }
}
