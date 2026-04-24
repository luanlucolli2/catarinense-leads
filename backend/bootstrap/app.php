<?php

use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
        $middleware->statefulApi();

        // Evita tentativa de route('login') em requests sem autenticação.
        $middleware->redirectGuestsTo('/login');
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('clt:refresh-admission-months')
            ->dailyAt('03:10')
            ->timezone('America/Sao_Paulo')
            ->runInBackground();

        $schedule->command('clt:dispatch-scheduled-consult-jobs')
            ->name('clt-dispatch-scheduled-consult-jobs')
            ->everyTenSeconds()
            ->withoutOverlapping(1);

        $schedule->command('presenca:dispatch-scheduled-consult-jobs')
            ->name('presenca-dispatch-scheduled-consult-jobs')
            ->everyTenSeconds()
            ->withoutOverlapping(1);

        $schedule->command('c6:purge-expired-links')
            ->everyThirtyMinutes()
            ->timezone('America/Sao_Paulo')
            ->runInBackground();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
