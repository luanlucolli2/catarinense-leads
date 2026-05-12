<?php

namespace App\Modules\Mercantil\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyMercantilWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('mercantil.webhook_secret');

        if (! is_string($configured) || $configured === '') {
            Log::critical('Mercantil webhook secret not configured in .env or config/mercantil.php');

            return response()->json([
                'error' => 'server_configuration_error',
                'message' => 'Internal server error.',
            ], 500);
        }

        $provided = $request->header('X-Mercantil-Secret-Key');

        if (! is_string($provided) || trim($provided) === '' || ! hash_equals($configured, trim($provided))) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        return $next($request);
    }
}
