<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\LeadExportController;
use App\Http\Controllers\Api\RollbackController;
use App\Http\Controllers\Api\CltConsultController;
use App\Http\Controllers\Api\FgtsOfflineController;

/*--------------------------------------------------
| Rotas Públicas
|--------------------------------------------------*/
Route::post('/login', [AuthController::class, 'login']);
Route::post('/login-token', [AuthController::class, 'loginToken']);

/*--------------------------------------------------
| Rotas Protegidas (Sanctum)
|--------------------------------------------------*/
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', fn(Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);

    /* Leads */
    Route::get('/leads/filters', [LeadController::class, 'filters']);
    Route::apiResource('leads', LeadController::class);

    /* Importação (FGTS) */
    Route::post('/import', [ImportController::class, 'store']);
    Route::get('/import/{importJob}', [ImportController::class, 'show'])->whereNumber('importJob');
    Route::get('/imports', [ImportController::class, 'index']);
    Route::get('/import/{importJob}/errors', [ImportController::class, 'errors'])->whereNumber('importJob');
    Route::get('/import/{importJob}/errors/export', [ImportController::class, 'exportErrors'])->whereNumber('importJob');
    Route::post('/leads/export', [LeadExportController::class, 'export']);

    /* Rollback da última importação */
    Route::post('/import/{importJob}/rollback', [RollbackController::class, 'store'])->whereNumber('importJob');

    /* Consulta CLT (Consignado do Trabalhador) */
    Route::get('/clt/consult-jobs', [CltConsultController::class, 'index']);
    Route::post('/clt/consult-jobs', [CltConsultController::class, 'store']);
    Route::get('/clt/consult-jobs/{id}', [CltConsultController::class, 'show'])->whereNumber('id');
    Route::get('/clt/consult-jobs/{id}/download', [CltConsultController::class, 'download'])->whereNumber('id');
    Route::get('/clt/consult-jobs/{id}/preview', [CltConsultController::class, 'downloadPreview'])->whereNumber('id');
    Route::post('/clt/consult-jobs/{id}/pause', [CltConsultController::class, 'pause'])->whereNumber('id');
    Route::post('/clt/consult-jobs/{id}/resume', [CltConsultController::class, 'resume'])->whereNumber('id');
    Route::post('/clt/consult-jobs/{id}/cancel', [CltConsultController::class, 'cancel'])->whereNumber('id');
    Route::delete('/clt/consult-jobs/{id}', [CltConsultController::class, 'destroy'])->whereNumber('id');

    /* FGTS Offline (Base OFF da FACTA) */
    Route::get('/fgts-off/consult-jobs', [FgtsOfflineController::class, 'index']);
    Route::post('/fgts-off/consult-jobs', [FgtsOfflineController::class, 'store']);
    Route::get('/fgts-off/consult-jobs/{id}', [FgtsOfflineController::class, 'show'])->whereNumber('id');
    Route::get('/fgts-off/consult-jobs/{id}/download', [FgtsOfflineController::class, 'download'])->whereNumber('id');

    // 🔁 PRÉVIA: gerar (assíncrono) + baixar
    Route::post('/fgts-off/consult-jobs/{id}/preview/generate', [FgtsOfflineController::class, 'requestPreview'])->whereNumber('id');
    Route::get('/fgts-off/consult-jobs/{id}/preview', [FgtsOfflineController::class, 'downloadPreview'])->whereNumber('id');

    Route::post('/fgts-off/consult-jobs/{id}/cancel', [FgtsOfflineController::class, 'cancel'])->whereNumber('id');
    Route::delete('/fgts-off/consult-jobs/{id}', [FgtsOfflineController::class, 'destroy'])->whereNumber('id');
});
