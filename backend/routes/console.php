<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Modules\CLT\Services\DispatchScheduledCltConsultJobs;
use App\Modules\CLT\Services\ResumeActiveCltConsultJobsService;
use App\Modules\Presenca\Services\DispatchScheduledPresencaConsultJobs;
use App\Modules\Presenca\Services\ResumeActivePresencaConsultJobsService;
use App\Modules\Vendeai\Services\BackfillVendeaiLeadProductKeysService;
use App\Modules\V8\Services\DispatchScheduledV8ConsultJobs;
use App\Modules\V8\Services\ResumeActiveV8ConsultJobsService;
use App\Modules\V8Fgts\Services\ResumeActiveV8FgtsJobsService;
use App\Models\C6AuthorizationLink;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Atualiza meses_admissao diariamente (não altera updated_at)
|--------------------------------------------------------------------------
| Estratégia leve para 1 vCPU / 2GB RAM: um único UPDATE set-based.
| Usa a data de hoje em America/Sao_Paulo e mantém updated_at intocado.
*/
Artisan::command('clt:refresh-admission-months', function () {
    $today = Carbon::now('America/Sao_Paulo')->toDateString();

    $affected = DB::table('clt_snapshots')
        ->whereNotNull('data_admissao')
        ->update([
            'meses_admissao' => DB::raw("GREATEST(TIMESTAMPDIFF(MONTH, data_admissao, DATE '{$today}'), 0)")
        ]);

    $this->info("clt_snapshots: meses_admissao atualizados em {$affected} registro(s).");
})->purpose('Recalcula meses_admissao com base em data_admissao (daily, set-based, sem tocar updated_at)');

Artisan::command('clt:dispatch-scheduled-consult-jobs', function () {
    $result = app(DispatchScheduledCltConsultJobs::class)->handle();

    if (($result['scanned'] ?? 0) === 0) {
        return;
    }

    $this->info(sprintf(
        'CLT agendado: %d verificado(s), %d despachado(s), %d falha(s).',
        (int) ($result['scanned'] ?? 0),
        (int) ($result['dispatched'] ?? 0),
        (int) ($result['failed'] ?? 0),
    ));
})->purpose('Despacha jobs CLT agendados cujo horário já venceu');

Artisan::command('presenca:dispatch-scheduled-consult-jobs', function () {
    $result = app(DispatchScheduledPresencaConsultJobs::class)->handle();

    if (($result['scanned'] ?? 0) === 0) {
        return;
    }

    $this->info(sprintf(
        'Presença agendado: %d verificado(s), %d despachado(s), %d falha(s).',
        (int) ($result['scanned'] ?? 0),
        (int) ($result['dispatched'] ?? 0),
        (int) ($result['failed'] ?? 0),
    ));
})->purpose('Despacha jobs Presença agendados cujo horário já venceu');

Artisan::command('v8:dispatch-scheduled-consult-jobs', function () {
    $result = app(DispatchScheduledV8ConsultJobs::class)->handle();

    if (($result['scanned'] ?? 0) === 0) {
        return;
    }

    $this->info(sprintf(
        'V8 agendado: %d verificado(s), %d despachado(s), %d falha(s).',
        (int) ($result['scanned'] ?? 0),
        (int) ($result['dispatched'] ?? 0),
        (int) ($result['failed'] ?? 0),
    ));
})->purpose('Despacha jobs V8 agendados cujo horário já venceu');

Artisan::command('v8-fgts:resume-active-jobs', function () {
    $result = app(ResumeActiveV8FgtsJobsService::class)->handle();

    $this->info(sprintf(
        'V8 FGTS ativos: %d verificado(s), %d preparo religado(s), %d batch religado(s).',
        (int) ($result['scanned'] ?? 0),
        (int) ($result['prepare_dispatched'] ?? 0),
        (int) ($result['batch_dispatched'] ?? 0),
    ));
})->purpose('Religa jobs V8 FGTS ativos após restart/deploy do worker');

Artisan::command('consult-jobs:resume-active-after-deploy', function () {
    $clt = app(ResumeActiveCltConsultJobsService::class)->handle();
    $v8 = app(ResumeActiveV8ConsultJobsService::class)->handle();
    $presenca = app(ResumeActivePresencaConsultJobsService::class)->handle();
    $v8Fgts = app(ResumeActiveV8FgtsJobsService::class)->handle();

    $this->info(sprintf(
        'Retomada pos-deploy: CLT %d/%d, V8 %d/%d, Presenca %d/%d, V8 FGTS prep %d batch %d de %d.',
        (int) ($clt['dispatched'] ?? 0),
        (int) ($clt['scanned'] ?? 0),
        (int) ($v8['dispatched'] ?? 0),
        (int) ($v8['scanned'] ?? 0),
        (int) ($presenca['dispatched'] ?? 0),
        (int) ($presenca['scanned'] ?? 0),
        (int) ($v8Fgts['prepare_dispatched'] ?? 0),
        (int) ($v8Fgts['batch_dispatched'] ?? 0),
        (int) ($v8Fgts['scanned'] ?? 0),
    ));
})->purpose('Religa jobs ativos de CLT, V8, Presença e V8 FGTS após deploy/restart do worker');

Artisan::command('c6:purge-expired-links', function () {
    $updated = C6AuthorizationLink::markExpired();

    $this->info("c6_authorization_links: {$updated} link(s) marcado(s) como expirado(s).");
})->purpose('Marca links C6 expirados (sem remoção)');

Artisan::command('vendeai:backfill-product-keys', function () {
    $result = app(BackfillVendeaiLeadProductKeysService::class)->handle();

    $this->info(sprintf(
        'VendeAI product_key: %d processado(s), %d atualizado(s), %d duplicado(s), %d tentativa(s) religada(s).',
        (int) ($result['processed'] ?? 0),
        (int) ($result['updated'] ?? 0),
        (int) ($result['duplicated'] ?? 0),
        (int) ($result['attempts_relinked'] ?? 0),
    ));
})->purpose('Divide leads VendeAI por conversa + produto e religa tentativas NewCorban');
