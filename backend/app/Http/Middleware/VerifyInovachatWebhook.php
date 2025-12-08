<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyInovachatWebhook
{
    /**
     * Verifica o segredo enviado pelo Flowbuilder (Inovachat).
     * Autenticação simples por shared secret em header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.inovachat.webhook_secret');

        // 1) Verifica configuração do servidor
        if (! is_string($configured) || $configured === '') {
            // Loga o erro crítico para o desenvolvedor
            Log::critical('Inovachat webhook secret not configured in .env or services.php');

            // Retorna JSON 500 (evita abort() para não vazar HTML)
            return response()->json([
                'error'   => 'server_configuration_error',
                'message' => 'Internal server error.',
            ], 500);
        }

        // 2) Busca o token
        $provided = $request->header('X-Inovachat-Secret')
            ?? $request->header('X-InovaChat-Secret')
            ?? $request->query('token');

        // 3) Valida assinatura
        if (! is_string($provided) || ! hash_equals($configured, $provided)) {
            // Retorna JSON 401 limpo (sem 'ok')
            return response()->json([
                'error'   => 'unauthorized',
                'message' => 'Invalid webhook signature.'
            ], 401);
        }

        return $next($request);
    }
}
