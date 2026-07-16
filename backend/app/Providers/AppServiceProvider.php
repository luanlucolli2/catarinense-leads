<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Database\Console\WipeCommand;
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
        $prohibitDestructiveDatabaseCommands = $this->app->environment('production');
        FreshCommand::prohibit($prohibitDestructiveDatabaseCommands);
        RefreshCommand::prohibit($prohibitDestructiveDatabaseCommands);
        ResetCommand::prohibit($prohibitDestructiveDatabaseCommands);
        RollbackCommand::prohibit($prohibitDestructiveDatabaseCommands);
        SeedCommand::prohibit($prohibitDestructiveDatabaseCommands);
        WipeCommand::prohibit($prohibitDestructiveDatabaseCommands);

        /**
         * Limite de tentativas de login.
         * Combina IP e identificador do usuário/email para reduzir brute-force e enumeração.
         * Ajuste os valores conforme sua necessidade.
         */
        RateLimiter::for('login', function (Request $request) {
            $identifier = (string) $request->input('email');
            $identifier = mb_strtolower(trim($identifier));
            if ($identifier === '') {
                $identifier = $request->ip();
            }

            $perMinuteIp = max(20, (int) config('auth.login_rate_limit.ip_per_minute', 120));
            $perMinuteIdentifier = max(20, (int) config('auth.login_rate_limit.identifier_per_minute', 120));

            return [
                // Conta compartilhada em IP corporativo: limite mais alto por IP.
                Limit::perMinute($perMinuteIp)->by('login:ip:'.$request->ip())->response(function () {
                    return response()->json([
                        'message' => 'Muitas tentativas. Tente novamente em instantes.'
                    ], 429);
                }),

                // Contenção por credencial (email) para reduzir brute-force.
                Limit::perMinute($perMinuteIdentifier)->by('login:id:'.$identifier)->response(function () {
                    return response()->json([
                        'message' => 'Muitas tentativas. Tente novamente em instantes.'
                    ], 429);
                }),
            ];
        });

        /**
         * Limites para endpoints C6 (leitura/listagem e escrita/geração).
         * Protege a API em infraestrutura pequena (1 vCPU / 2GB) sem travar uso normal.
         */
        RateLimiter::for('c6-links-read', function (Request $request) {
            $userIdentifier = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
            $perMinuteUser = max(60, (int) config('c6bank.rate_limit.read_per_minute_user', 600));
            $perMinuteIp = max($perMinuteUser, (int) config('c6bank.rate_limit.read_per_minute_ip', 1800));

            return [
                // Contenção principal por usuário autenticado (conta compartilhada).
                Limit::perMinute($perMinuteUser)->by('c6-read:user:'.$userIdentifier)->response(function () {
                    return response()->json([
                        'message' => 'Muitas consultas de links C6. Aguarde alguns segundos e tente novamente.'
                    ], 429);
                }),

                // Cinto de segurança por IP em patamar mais alto para não punir operação corporativa em IP único.
                Limit::perMinute($perMinuteIp)->by('c6-read:ip:'.$request->ip())->response(function () {
                    return response()->json([
                        'message' => 'Muitas consultas de links C6 a partir deste IP. Aguarde alguns segundos.'
                    ], 429);
                }),
            ];
        });

        RateLimiter::for('c6-links-write', function (Request $request) {
            $userIdentifier = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
            $perMinuteUser = max(20, (int) config('c6bank.rate_limit.write_per_minute_user', 90));
            $perMinuteIp = max($perMinuteUser, (int) config('c6bank.rate_limit.write_per_minute_ip', 300));

            return [
                Limit::perMinute($perMinuteUser)->by('c6-write:user:'.$userIdentifier)->response(function () {
                    return response()->json([
                        'message' => 'Muitas tentativas de geração de link C6. Aguarde alguns segundos.'
                    ], 429);
                }),

                Limit::perMinute($perMinuteIp)->by('c6-write:ip:'.$request->ip())->response(function () {
                    return response()->json([
                        'message' => 'Muitas tentativas de geração de link C6 a partir deste IP. Aguarde alguns segundos.'
                    ], 429);
                }),
            ];
        });
    }
}
