<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\LeadExportController;

/*--------------------------------------------------
| Rotas Públicas
|--------------------------------------------------*/
Route::post('/login', [AuthController::class, 'login']);
Route::post('/leads/export', [LeadExportController::class, 'export']);

/*--------------------------------------------------
| Rotas Protegidas (Sanctum)
|--------------------------------------------------*/
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', fn(Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/leads/filters', [LeadController::class, 'filters']);   // 🆕

    Route::apiResource('leads', LeadController::class);
    /* Importação */
    Route::post('/import', [ImportController::class, 'store']);          // cria job
    Route::get('/import/{importJob}', [ImportController::class, 'show'])// status
        ->whereNumber('importJob');

    /* 🆕 lista jobs do usuário – ?status=em_progresso,pendente */
    Route::get('/imports', [ImportController::class, 'index']);
    Route::post('/leads/export', [LeadExportController::class, 'export']);

});
