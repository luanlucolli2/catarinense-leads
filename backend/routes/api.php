<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthController;
use App\Modules\Leads\Controllers\LeadController;
use App\Modules\Leads\Controllers\ImportController;
use App\Modules\Leads\Controllers\LeadExportController;
use App\Modules\Leads\Controllers\RollbackController;
use App\Modules\FactaCLT\Controllers\FactaCltConsultController;
use App\Modules\HubCredito\Controllers\HubCreditoConsultController;
use App\Modules\V8\Controllers\V8ConsultController;
use App\Modules\V8Fgts\Controllers\V8FgtsConsultController;
use App\Modules\FgtsOffline\Controllers\FgtsOfflineController;
use App\Modules\Presenca\Controllers\PresencaConsultController;
use App\Modules\SomaClt\Controllers\SomaCltConsultController;
use App\Http\Controllers\Api\C6AuthorizationLinkController;
use App\Http\Controllers\Api\C6AuthorizationLinkListController;
use App\Http\Controllers\Api\ShortLinkProxyController;
use App\Modules\Vendeai\Controllers\VendeaiExportController;
use App\Modules\Vendeai\Controllers\VendeaiFilterOptionsController;
use App\Modules\Vendeai\Controllers\VendeaiLeadListController;
use App\Modules\Vendeai\Controllers\VendeaiMetricsController;
use App\Modules\Vendeai\Controllers\VendeaiNewCorbanProposalAttemptListController;
use App\Modules\Vendeai\Controllers\VendeaiProposalCreatedWebhookListController;
use App\Modules\Vendeai\Controllers\VendeaiProposalRetryController;
use App\Modules\Vendeai\Controllers\VendeaiWebhookController;

// ✅ NOVO: URA
use App\Http\Controllers\Api\UraSendOfficialTemplateController;
use App\Http\Middleware\VerifyUraWebhook;
use App\Modules\DisparosWhatsappVendeai\Controllers\MailingInboxesController;
use App\Modules\DisparosWhatsappVendeai\Controllers\RegisteredLeadsPreviewController;
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

Route::post('/webhooks/vendeai/{token}', VendeaiWebhookController::class);

/**
 * Endpoints autenticados via Sanctum (SPA / API interna).
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    /* Links curtos (Multi Consulta) */
    Route::get('/links', [ShortLinkProxyController::class, 'index']);
    Route::post('/links', [ShortLinkProxyController::class, 'store']);
    Route::get('/links/{id}', [ShortLinkProxyController::class, 'show']);
    Route::patch('/links/{id}', [ShortLinkProxyController::class, 'update']);
    Route::delete('/links/{id}', [ShortLinkProxyController::class, 'destroy']);
    Route::post('/links/{id}/disable', [ShortLinkProxyController::class, 'disable']);
    Route::post('/links/{id}/enable', [ShortLinkProxyController::class, 'enable']);
    Route::get('/links/{id}/analytics', [ShortLinkProxyController::class, 'analytics']);
    Route::get('/links/{id}/clicks', [ShortLinkProxyController::class, 'clicks']);
    Route::get('/links/{id}/export.csv', [ShortLinkProxyController::class, 'export']);

    /* Parceiros */
    Route::post('/uy3/posts/export', [Uy3PostExportController::class, 'export'])
        ->middleware('throttle:30,1');
    Route::get('/uy3/posts/export/{token}', [Uy3PostExportController::class, 'status'])
        ->middleware('throttle:120,1');
    Route::get('/uy3/posts/export/{token}/download', [Uy3PostExportController::class, 'download'])
        ->middleware('throttle:30,1');

    Route::get('/uy3/posts', Uy3PostListController::class)
        ->middleware('throttle:120,1');

    Route::get('/vendeai/proposal-created-webhooks', VendeaiProposalCreatedWebhookListController::class)
        ->middleware('throttle:120,1');

    Route::get('/vendeai/leads', VendeaiLeadListController::class)
        ->middleware('throttle:120,1');

    Route::get('/vendeai/metrics', VendeaiMetricsController::class)
        ->middleware('throttle:120,1');

    Route::get('/vendeai/filter-options', VendeaiFilterOptionsController::class)
        ->middleware('throttle:120,1');

    Route::get('/vendeai/newcorban-proposal-attempts', VendeaiNewCorbanProposalAttemptListController::class)
        ->middleware('throttle:120,1');

    Route::post('/vendeai/exports/leads', [VendeaiExportController::class, 'leads'])
        ->middleware('throttle:30,1');
    Route::get('/vendeai/exports/{token}', [VendeaiExportController::class, 'status'])
        ->middleware('throttle:120,1');
    Route::get('/vendeai/exports/{token}/download', [VendeaiExportController::class, 'download'])
        ->middleware('throttle:30,1');

    Route::post('/vendeai/proposals/retry-newcorban', VendeaiProposalRetryController::class)
        ->middleware('throttle:30,1');

    /* C6 */
    Route::post('/c6/authorization-link', C6AuthorizationLinkController::class)
        ->middleware('throttle:c6-links-write');
    Route::get('/c6/authorization-links', C6AuthorizationLinkListController::class)
        ->middleware('throttle:c6-links-read');

    /* Leads */
    Route::get('/leads/filters', [LeadController::class, 'filters']);
    Route::apiResource('leads', LeadController::class)->only(['index', 'show']);
    Route::post('/leads/search', [LeadController::class, 'search']);
    Route::post('/disparos-whatsapp-vendeai/leads/preview', RegisteredLeadsPreviewController::class)
        ->middleware('throttle:30,1');
    Route::get('/disparos-whatsapp-vendeai/inboxes', MailingInboxesController::class)
        ->middleware('throttle:30,1');

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
    Route::get('/facta-clt/consult-jobs', [FactaCltConsultController::class, 'index']);
    Route::post('/facta-clt/consult-jobs', [FactaCltConsultController::class, 'store']);
    Route::get('/facta-clt/consult-jobs/{id}', [FactaCltConsultController::class, 'show'])->whereNumber('id');
    Route::get('/facta-clt/consult-jobs/{id}/http-counters', [FactaCltConsultController::class, 'httpCounters'])->whereNumber('id');
    Route::get('/facta-clt/consult-jobs/{id}/download', [FactaCltConsultController::class, 'download'])->whereNumber('id');
    Route::post('/facta-clt/consult-jobs/{id}/preview/generate', [FactaCltConsultController::class, 'requestPreview'])->whereNumber('id');
    Route::get('/facta-clt/consult-jobs/{id}/preview', [FactaCltConsultController::class, 'downloadPreview'])->whereNumber('id');
    Route::post('/facta-clt/consult-jobs/{id}/pause', [FactaCltConsultController::class, 'pause'])->whereNumber('id');
    Route::post('/facta-clt/consult-jobs/{id}/resume', [FactaCltConsultController::class, 'resume'])->whereNumber('id');
    Route::post('/facta-clt/consult-jobs/{id}/cancel', [FactaCltConsultController::class, 'cancel'])->whereNumber('id');
    Route::post('/facta-clt/consult-jobs/{id}/phase2/rerun', [FactaCltConsultController::class, 'rerunPhase2'])->whereNumber('id');
    Route::delete('/facta-clt/consult-jobs/{id}', [FactaCltConsultController::class, 'destroy'])->whereNumber('id');

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

    /* HubCredito CLT */
    Route::get('/hubcredito-clt/consult-jobs', [HubCreditoConsultController::class, 'index']);
    Route::post('/hubcredito-clt/consult-jobs', [HubCreditoConsultController::class, 'store']);
    Route::get('/hubcredito-clt/consult-jobs/{id}', [HubCreditoConsultController::class, 'show'])->whereNumber('id');
    Route::get('/hubcredito-clt/consult-jobs/{id}/download', [HubCreditoConsultController::class, 'download'])->whereNumber('id');
    Route::post('/hubcredito-clt/consult-jobs/{id}/preview/generate', [HubCreditoConsultController::class, 'requestPreview'])->whereNumber('id');
    Route::get('/hubcredito-clt/consult-jobs/{id}/preview', [HubCreditoConsultController::class, 'downloadPreview'])->whereNumber('id');
    Route::post('/hubcredito-clt/consult-jobs/{id}/pause', [HubCreditoConsultController::class, 'pause'])->whereNumber('id');
    Route::post('/hubcredito-clt/consult-jobs/{id}/resume', [HubCreditoConsultController::class, 'resume'])->whereNumber('id');
    Route::post('/hubcredito-clt/consult-jobs/{id}/cancel', [HubCreditoConsultController::class, 'cancel'])->whereNumber('id');
    Route::delete('/hubcredito-clt/consult-jobs/{id}', [HubCreditoConsultController::class, 'destroy'])->whereNumber('id');

    /* V8 FGTS */
    Route::get('/v8-fgts/consult-jobs', [V8FgtsConsultController::class, 'index']);
    Route::post('/v8-fgts/consult-jobs', [V8FgtsConsultController::class, 'store']);
    Route::get('/v8-fgts/consult-jobs/{id}', [V8FgtsConsultController::class, 'show'])->whereNumber('id');
    Route::get('/v8-fgts/consult-jobs/{id}/download', [V8FgtsConsultController::class, 'download'])->whereNumber('id');
    Route::post('/v8-fgts/consult-jobs/{id}/preview/generate', [V8FgtsConsultController::class, 'requestPreview'])->whereNumber('id');
    Route::get('/v8-fgts/consult-jobs/{id}/preview', [V8FgtsConsultController::class, 'downloadPreview'])->whereNumber('id');
    Route::post('/v8-fgts/consult-jobs/{id}/cancel', [V8FgtsConsultController::class, 'cancel'])->whereNumber('id');
    Route::delete('/v8-fgts/consult-jobs/{id}', [V8FgtsConsultController::class, 'destroy'])->whereNumber('id');

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

    /* Soma CLT */
    Route::get('/soma-clt/consult-jobs', [SomaCltConsultController::class, 'index']);
    Route::post('/soma-clt/consult-jobs', [SomaCltConsultController::class, 'store']);
    Route::get('/soma-clt/consult-jobs/{id}', [SomaCltConsultController::class, 'show'])->whereNumber('id');
    Route::get('/soma-clt/consult-jobs/{id}/download', [SomaCltConsultController::class, 'download'])->whereNumber('id');
    Route::post('/soma-clt/consult-jobs/{id}/preview/generate', [SomaCltConsultController::class, 'requestPreview'])->whereNumber('id');
    Route::get('/soma-clt/consult-jobs/{id}/preview', [SomaCltConsultController::class, 'downloadPreview'])->whereNumber('id');
    Route::post('/soma-clt/consult-jobs/{id}/pause', [SomaCltConsultController::class, 'pause'])->whereNumber('id');
    Route::post('/soma-clt/consult-jobs/{id}/resume', [SomaCltConsultController::class, 'resume'])->whereNumber('id');
    Route::post('/soma-clt/consult-jobs/{id}/cancel', [SomaCltConsultController::class, 'cancel'])->whereNumber('id');
    Route::delete('/soma-clt/consult-jobs/{id}', [SomaCltConsultController::class, 'destroy'])->whereNumber('id');
});
