<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
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

Artisan::command('c6:purge-expired-links', function () {
    $updated = C6AuthorizationLink::markExpired();

    $this->info("c6_authorization_links: {$updated} link(s) marcado(s) como expirado(s).");
})->purpose('Marca links C6 expirados (sem remoção)');
