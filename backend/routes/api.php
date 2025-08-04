<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\LeadExportController;
use App\Http\Controllers\Api\RollbackController;  // 👈 aqui

/*--------------------------------------------------
| Rotas Públicas
|--------------------------------------------------*/
Route::middleware('web')->post('/login', [AuthController::class, 'login']);

/*--------------------------------------------------
| Rotas Protegidas (Sanctum)
|--------------------------------------------------*/
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', fn(Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/leads/filters', [LeadController::class, 'filters']);

    Route::apiResource('leads', LeadController::class);

    /* Importação */
    Route::post('/import', [ImportController::class, 'store']);
    Route::get('/import/{importJob}', [ImportController::class, 'show'])
        ->whereNumber('importJob');
    Route::get('/imports', [ImportController::class, 'index']);
    Route::get('/import/{importJob}/errors', [ImportController::class, 'errors'])
        ->whereNumber('importJob');
    Route::get('/import/{importJob}/errors/export', [ImportController::class, 'exportErrors'])
        ->whereNumber('importJob');
    Route::post('/leads/export', [LeadExportController::class, 'export']);

    /* Rollback da última importação */
    Route::post(
        '/import/{importJob}/rollback',
        [RollbackController::class, 'store']
    )
        ->whereNumber('importJob');
});
