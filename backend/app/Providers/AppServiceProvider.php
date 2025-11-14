<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Limite de tentativas de login.
         * Combina IP e identificador do usuário/email para reduzir brute-force e enumeração.
         * Ajuste os valores conforme sua necessidade.
         */
        RateLimiter::for('login', function (Request $request) {
            $identifier = $request->user()?->getAuthIdentifier()
                ?? (string) $request->input('email')
                ?? $request->ip();

            return [
                // Máx. 5 por minuto por IP
                Limit::perMinute(5)->by($request->ip())->response(function () {
                    return response()->json([
                        'message' => 'Muitas tentativas. Tente novamente em instantes.'
                    ], 429);
                }),

                // Máx. 5 por minuto por usuário/email
                Limit::perMinute(5)->by('login:'.$identifier)->response(function () {
                    return response()->json([
                        'message' => 'Muitas tentativas. Tente novamente em instantes.'
                    ], 429);
                }),
            ];
        });
    }
}
