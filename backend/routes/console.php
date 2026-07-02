<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Modules\CLT\Services\DispatchScheduledCltConsultJobs;
use App\Modules\Presenca\Services\DispatchScheduledPresencaConsultJobs;
use App\Modules\Vendeai\Services\NewCorbanCatalogValidationService;
use App\Modules\Uy3\Services\BackfillUy3SnapshotsService;
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

Artisan::command('newcorban:validate-config', function () {
    try {
        $result = app(NewCorbanCatalogValidationService::class)->handle();
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    foreach (($result['catalogs'] ?? []) as $catalog => $count) {
        $this->line(sprintf('%s: %d registro(s).', $catalog, (int) $count));
    }

    foreach (($result['warnings'] ?? []) as $warning) {
        $this->warn($warning);
    }

    if (($result['ok'] ?? false) !== true) {
        foreach (($result['errors'] ?? []) as $error) {
            $this->error($error);
        }

        return 1;
    }

    $this->info('NewCorban config validada com sucesso.');

    return 0;
})->purpose('Valida mapeamentos locais da NewCorban contra os catalogos da API nova');

Artisan::command('uy3:backfill-snapshots', function () {
    $result = app(BackfillUy3SnapshotsService::class)->handle();

    $this->info(sprintf(
        'UY3 snapshots: %d lido(s), %d persistido(s), %d ignorado(s).',
        (int) ($result['scanned'] ?? 0),
        (int) ($result['persisted'] ?? 0),
        (int) ($result['skipped'] ?? 0),
    ));
})->purpose('Reprocessa uy3_webhook_posts e preenche leads + uy3_snapshots');
