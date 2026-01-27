<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\InovachatC6WaitQueueWebhookController;
use App\Http\Middleware\VerifyInovachatQueueWebhook;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\LeadExportController;
use App\Http\Controllers\Api\RollbackController;
use App\Http\Controllers\Api\CltConsultController;
use App\Http\Controllers\Api\V8ConsultController;
use App\Http\Controllers\Api\FgtsOfflineController;
use App\Http\Controllers\Api\InovachatTriageController;
use App\Http\Middleware\VerifyInovachatWebhook;

// ✅ NOVO: URA
use App\Http\Controllers\Api\UraSendOfficialTemplateController;
use App\Http\Middleware\VerifyUraWebhook;

/**
 * Endpoints públicos de autenticação.
 * Rate limit nomeado "login".
 */
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/login-token', [AuthController::class, 'loginToken'])->middleware('throttle:login');

/**
 * Endpoint público para o Flowbuilder do Inovachat.
 * Autenticado via shared secret no header X-Inovachat-Secret.
 * NÃO passa por auth:sanctum.
 */
Route::post('/inovachat/triage', InovachatTriageController::class)
    ->middleware(VerifyInovachatWebhook::class);

/**
 * Webhook de fila (Inovachat) — usado na fila "CLT LINK C6 - Aguardando autorização" (sec 99).
 * Autenticado via token_origin do payload.
 */
Route::post('/inovachat/queue-webhook/c6-wait', InovachatC6WaitQueueWebhookController::class)
    ->middleware(VerifyInovachatQueueWebhook::class);

/**
 * ✅ URA → sua API (envia template oficial sem variável via Inovachat)
 * Autenticado via shared secret (X-Ura-Secret / Authorization Bearer).
 * Não usa token de conexão no request: a conexão é randomizada no backend.
 */
Route::post('/ura/messages/send-official-template', UraSendOfficialTemplateController::class)
    ->middleware([VerifyUraWebhook::class, 'throttle:60,1']);

/**
 * Endpoints autenticados via Sanctum (SPA / API interna).
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    /* Leads */
    Route::get('/leads/filters', [LeadController::class, 'filters']);
    Route::apiResource('leads', LeadController::class);
    Route::post('/leads/search', [LeadController::class, 'search']);

    /* Importação (FGTS) */
    Route::post('/import', [ImportController::class, 'store']);
    Route::get('/import/{importJob}', [ImportController::class, 'show'])->whereNumber('importJob');
    Route::get('/imports', [ImportController::class, 'index']);
    Route::get('/import/{importJob}/errors', [ImportController::class, 'errors'])->whereNumber('importJob');
    Route::get('/import/{importJob}/errors/export', [ImportController::class, 'exportErrors'])->whereNumber('importJob');

    /* Leads Export (assíncrono) */
    Route::post('/leads/export', [LeadExportController::class, 'export']);
    Route::get('/leads/export/{token}', [LeadExportController::class, 'status']);
    Route::get('/leads/export/{token}/download', [LeadExportController::class, 'download']);

    /* Rollback da última importação */
    Route::post('/import/{importJob}/rollback', [RollbackController::class, 'store'])->whereNumber('importJob');

    /* CLT */
    Route::get('/clt/consult-jobs', [CltConsultController::class, 'index']);
    Route::post('/clt/consult-jobs', [CltConsultController::class, 'store']);
    Route::get('/clt/consult-jobs/{id}', [CltConsultController::class, 'show'])->whereNumber('id');
    Route::get('/clt/consult-jobs/{id}/download', [CltConsultController::class, 'download'])->whereNumber('id');
    Route::post('/clt/consult-jobs/{id}/preview/generate', [CltConsultController::class, 'requestPreview'])->whereNumber('id');
    Route::get('/clt/consult-jobs/{id}/preview', [CltConsultController::class, 'downloadPreview'])->whereNumber('id');
    Route::post('/clt/consult-jobs/{id}/cancel', [CltConsultController::class, 'cancel'])->whereNumber('id');
    Route::delete('/clt/consult-jobs/{id}', [CltConsultController::class, 'destroy'])->whereNumber('id');

    /* V8 */
    Route::get('/v8/consult-jobs', [V8ConsultController::class, 'index']);
    Route::post('/v8/consult-jobs', [V8ConsultController::class, 'store']);
    Route::get('/v8/consult-jobs/{id}', [V8ConsultController::class, 'show'])->whereNumber('id');
    Route::get('/v8/consult-jobs/{id}/download', [V8ConsultController::class, 'download'])->whereNumber('id');
    Route::post('/v8/consult-jobs/{id}/preview/generate', [V8ConsultController::class, 'requestPreview'])->whereNumber('id');
    Route::get('/v8/consult-jobs/{id}/preview', [V8ConsultController::class, 'downloadPreview'])->whereNumber('id');
    Route::post('/v8/consult-jobs/{id}/cancel', [V8ConsultController::class, 'cancel'])->whereNumber('id');
    Route::delete('/v8/consult-jobs/{id}', [V8ConsultController::class, 'destroy'])->whereNumber('id');

    /* FGTS Offline */
    Route::get('/fgts-off/consult-jobs', [FgtsOfflineController::class, 'index']);
    Route::post('/fgts-off/consult-jobs', [FgtsOfflineController::class, 'store']);
    Route::get('/fgts-off/consult-jobs/{id}', [FgtsOfflineController::class, 'show'])->whereNumber('id');
    Route::get('/fgts-off/consult-jobs/{id}/download', [FgtsOfflineController::class, 'download'])->whereNumber('id');
    Route::post('/fgts-off/consult-jobs/{id}/preview/generate', [FgtsOfflineController::class, 'requestPreview'])->whereNumber('id');
    Route::get('/fgts-off/consult-jobs/{id}/preview', [FgtsOfflineController::class, 'downloadPreview'])->whereNumber('id');
    Route::post('/fgts-off/consult-jobs/{id}/cancel', [FgtsOfflineController::class, 'cancel'])->whereNumber('id');
    Route::delete('/fgts-off/consult-jobs/{id}', [FgtsOfflineController::class, 'destroy'])->whereNumber('id');
});
