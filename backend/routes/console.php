<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Modules\CLT\Services\DispatchScheduledCltConsultJobs;
use App\Modules\Presenca\Services\DispatchScheduledPresencaConsultJobs;
use App\Modules\Vendeai\Services\BackfillVendeaiLeadProductKeysService;
use App\Modules\V8\Services\DispatchScheduledV8ConsultJobs;
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
