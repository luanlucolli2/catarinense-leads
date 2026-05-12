<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthController;
use App\Modules\Leads\Controllers\LeadController;
use App\Modules\Leads\Controllers\ImportController;
use App\Modules\Leads\Controllers\LeadExportController;
use App\Modules\Leads\Controllers\RollbackController;
use App\Modules\CLT\Controllers\CltConsultController;
use App\Modules\V8\Controllers\V8ConsultController;
use App\Modules\FgtsOffline\Controllers\FgtsOfflineController;
use App\Modules\Presenca\Controllers\PresencaConsultController;
use App\Http\Controllers\Api\C6AuthorizationLinkController;
use App\Http\Controllers\Api\C6AuthorizationLinkListController;

// ✅ NOVO: URA
use App\Http\Controllers\Api\UraSendOfficialTemplateController;
use App\Http\Middleware\VerifyUraWebhook;
use App\Modules\Uy3\Controllers\Uy3PostExportController;
use App\Modules\Uy3\Controllers\Uy3PostListController;
use App\Modules\Uy3\Controllers\Uy3WebhookPostController;
use App\Modules\Uy3\Middleware\VerifyUy3Webhook;
use App\Modules\Mercantil\Controllers\MercantilSnapshotWebhookController;
use App\Modules\Mercantil\Middleware\VerifyMercantilWebhook;

/**
 * Endpoints públicos de autenticação.
 * Rate limit nomeado "login".
 */
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/login-token', [AuthController::class, 'loginToken'])->middleware('throttle:login');

/**
 * ✅ URA → sua API (envia template oficial sem variável via Inovachat)
 * Autenticado via shared secret (X-Ura-Secret / Authorization Bearer).
 * Não usa token de conexão no request: a conexão é randomizada no backend.
 */
Route::post('/ura/messages/send-official-template', UraSendOfficialTemplateController::class)
    ->middleware([VerifyUraWebhook::class, 'throttle:60,1']);

/**
 * ✅ UY3 → sua API (webhook público de posts)
 * Autenticado via shared secret (Secret-Key / X-Secret-Key / X-UY3-Secret-Key).
 * O payload JSON é persistido de forma síncrona antes de responder.
 */
Route::post('/webhooks/uy3/posts', Uy3WebhookPostController::class)
    ->middleware([VerifyUy3Webhook::class]);

Route::post('/webhooks/mercantil/snapshots', MercantilSnapshotWebhookController::class)
    ->middleware([VerifyMercantilWebhook::class, 'throttle:600,1']);

/**
 * Endpoints autenticados via Sanctum (SPA / API interna).
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    /* Parceiros */
    Route::post('/uy3/posts/export', [Uy3PostExportController::class, 'export'])
        ->middleware('throttle:30,1');
    Route::get('/uy3/posts/export/{token}', [Uy3PostExportController::class, 'status'])
        ->middleware('throttle:120,1');
    Route::get('/uy3/posts/export/{token}/download', [Uy3PostExportController::class, 'download'])
        ->middleware('throttle:30,1');

    Route::get('/uy3/posts', Uy3PostListController::class)
        ->middleware('throttle:120,1');

    /* C6 */
    Route::post('/c6/authorization-link', C6AuthorizationLinkController::class)
        ->middleware('throttle:c6-links-write');
    Route::get('/c6/authorization-links', C6AuthorizationLinkListController::class)
        ->middleware('throttle:c6-links-read');

    /* Leads */
    Route::get('/leads/filters', [LeadController::class, 'filters']);
    Route::apiResource('leads', LeadController::class)->only(['index', 'show']);
    Route::post('/leads/search', [LeadController::class, 'search']);

    /* Importação (FGTS) */
    Route::post('/import', [ImportController::class, 'store']);
    Route::get('/import/{importJob}', [ImportController::class, 'show'])->whereNumber('importJob');
    Route::post('/import/{importJob}/cancel', [ImportController::class, 'cancel'])->whereNumber('importJob');
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
    Route::get('/clt/consult-jobs/{id}/http-counters', [CltConsultController::class, 'httpCounters'])->whereNumber('id');
    Route::get('/clt/consult-jobs/{id}/download', [CltConsultController::class, 'download'])->whereNumber('id');
    Route::post('/clt/consult-jobs/{id}/preview/generate', [CltConsultController::class, 'requestPreview'])->whereNumber('id');
    Route::get('/clt/consult-jobs/{id}/preview', [CltConsultController::class, 'downloadPreview'])->whereNumber('id');
    Route::post('/clt/consult-jobs/{id}/pause', [CltConsultController::class, 'pause'])->whereNumber('id');
    Route::post('/clt/consult-jobs/{id}/resume', [CltConsultController::class, 'resume'])->whereNumber('id');
    Route::post('/clt/consult-jobs/{id}/cancel', [CltConsultController::class, 'cancel'])->whereNumber('id');
    Route::post('/clt/consult-jobs/{id}/phase2/rerun', [CltConsultController::class, 'rerunPhase2'])->whereNumber('id');
    Route::delete('/clt/consult-jobs/{id}', [CltConsultController::class, 'destroy'])->whereNumber('id');

    /* V8 */
    Route::get('/v8/consult-jobs', [V8ConsultController::class, 'index']);
    Route::post('/v8/consult-jobs', [V8ConsultController::class, 'store']);
    Route::get('/v8/consult-jobs/{id}', [V8ConsultController::class, 'show'])->whereNumber('id');
    Route::get('/v8/consult-jobs/{id}/download', [V8ConsultController::class, 'download'])->whereNumber('id');
    Route::post('/v8/consult-jobs/{id}/preview/generate', [V8ConsultController::class, 'requestPreview'])->whereNumber('id');
    Route::get('/v8/consult-jobs/{id}/preview', [V8ConsultController::class, 'downloadPreview'])->whereNumber('id');
    Route::post('/v8/consult-jobs/{id}/pause', [V8ConsultController::class, 'pause'])->whereNumber('id');
    Route::post('/v8/consult-jobs/{id}/resume', [V8ConsultController::class, 'resume'])->whereNumber('id');
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

    /* Banco Presença */
    Route::get('/presenca/consult-jobs', [PresencaConsultController::class, 'index']);
    Route::post('/presenca/consult-jobs', [PresencaConsultController::class, 'store']);
    Route::get('/presenca/consult-jobs/{id}', [PresencaConsultController::class, 'show'])->whereNumber('id');
    Route::get('/presenca/consult-jobs/{id}/download', [PresencaConsultController::class, 'download'])->whereNumber('id');
    Route::post('/presenca/consult-jobs/{id}/preview/generate', [PresencaConsultController::class, 'requestPreview'])->whereNumber('id');
    Route::get('/presenca/consult-jobs/{id}/preview', [PresencaConsultController::class, 'downloadPreview'])->whereNumber('id');
    Route::post('/presenca/consult-jobs/{id}/pause', [PresencaConsultController::class, 'pause'])->whereNumber('id');
    Route::post('/presenca/consult-jobs/{id}/resume', [PresencaConsultController::class, 'resume'])->whereNumber('id');
    Route::post('/presenca/consult-jobs/{id}/cancel', [PresencaConsultController::class, 'cancel'])->whereNumber('id');
    Route::delete('/presenca/consult-jobs/{id}', [PresencaConsultController::class, 'destroy'])->whereNumber('id');
});
