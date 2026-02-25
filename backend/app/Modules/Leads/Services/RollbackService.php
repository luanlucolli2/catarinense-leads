<?php

namespace App\Modules\Leads\Services;

use App\Models\ImportJob;
use App\Models\Lead;
use App\Models\LeadContract;
use App\Models\Vendor;
use App\Models\Backup\LeadBackup;
use App\Models\Backup\LeadContractBackup;
use App\Models\Backup\VendorBackup;
use App\Models\LeadImport;
use Illuminate\Support\Facades\DB;

class RollbackService
{
    /**
     * Roda o rollback do job fornecido.
     *
     * Estratégia:
     *  - Descobrir leads inseridos no job (preferência pelo backup.was_new, com fallback na pivot).
     *  - Remover dependências desses leads (pivot e contratos) antes de deletá-los.
     *  - Restaurar estado dos leads atualizados a partir do snapshot.
     *  - Remover contratos inseridos em leads pré-existentes.
     *  - Remover vendors criados que ficaram sem contratos.
     *  - Marcar job como revertido e limpar resíduos (pivot/backups).
     */
    public function rollback(ImportJob $job): void
    {
        $chunkSize = max(100, (int) config('leads.rollback.chunk_size', 1000));

        // 0..2) Remove dependências e leads inseridos no job em lotes pequenos.
        $this->rollbackInsertedLeadsFromBackups($job->id, $chunkSize);
        $this->rollbackInsertedLeadsFromPivotFallback($job->id, $chunkSize);

        // 3) Restaura dados dos leads atualizados em batch (upsert por id).
        $this->restoreUpdatedLeads($job->id, $chunkSize);

        // 4) Remove contratos inseridos em leads pré-existentes.
        $this->rollbackInsertedContracts($job->id, $chunkSize);

        // 5) Remove vendors criados que ficaram órfãos.
        $this->rollbackOrphanVendors($job->id, $chunkSize);

        // 6) Marca job como revertido e limpa resíduos.
        DB::transaction(function () use ($job): void {
            $job->update([
                'status'         => 'revertido',
                'rolled_back_at' => now(),
            ]);

            LeadImport::where('import_job_id', $job->id)->delete();
            LeadBackup::where('import_job_id', $job->id)->delete();
            LeadContractBackup::where('import_job_id', $job->id)->delete();
            VendorBackup::where('import_job_id', $job->id)->delete();
        });
    }

    private function rollbackInsertedLeadsFromBackups(int $jobId, int $chunkSize): void
    {
        LeadBackup::query()
            ->where('import_job_id', $jobId)
            ->where('was_new', true)
            ->select(['id', 'lead_id'])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows): void {
                $leadIds = $rows->pluck('lead_id')->filter()->unique()->values()->all();
                $this->deleteLeadGraphByIds($leadIds);
            }, 'id');
    }

    private function rollbackInsertedLeadsFromPivotFallback(int $jobId, int $chunkSize): void
    {
        DB::table('lead_imports as li')
            ->select('li.lead_id')
            ->where('li.import_job_id', $jobId)
            ->where('li.action', 'insert')
            ->whereNotExists(function ($q) use ($jobId) {
                $q->selectRaw('1')
                    ->from('lead_backups as lb')
                    ->whereColumn('lb.lead_id', 'li.lead_id')
                    ->where('lb.import_job_id', $jobId)
                    ->where('lb.was_new', true);
            })
            ->orderBy('li.lead_id')
            ->chunkById($chunkSize, function ($rows): void {
                $leadIds = collect($rows)->pluck('lead_id')->filter()->unique()->values()->all();
                $this->deleteLeadGraphByIds($leadIds);
            }, 'lead_id');
    }

    private function deleteLeadGraphByIds(array $leadIds): void
    {
        if (empty($leadIds)) {
            return;
        }

        DB::transaction(function () use ($leadIds): void {
            LeadContract::whereIn('lead_id', $leadIds)->delete();
            Lead::whereIn('id', $leadIds)->delete();
        });
    }

    private function restoreUpdatedLeads(int $jobId, int $chunkSize): void
    {
        LeadBackup::query()
            ->where('import_job_id', $jobId)
            ->where('was_new', false)
            ->whereNotIn('lead_id', function ($q) use ($jobId) {
                $q->select('lead_id')
                    ->from('lead_backups')
                    ->where('import_job_id', $jobId)
                    ->where('was_new', true);
            })
            ->select([
                'id',
                'lead_id',
                'cpf',
                'nome',
                'data_nascimento',
                'fone1',
                'classe_fone1',
                'fone2',
                'classe_fone2',
                'fone3',
                'classe_fone3',
                'fone4',
                'classe_fone4',
                'consulta',
                'data_atualizacao',
                'saldo',
                'libera',
            ])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows): void {
                if ($rows->isEmpty()) {
                    return;
                }

                $now = now();
                $payload = [];

                foreach ($rows as $bkp) {
                    if (!$bkp->lead_id) {
                        continue;
                    }

                    $payload[] = [
                        'id'               => $bkp->lead_id,
                        'cpf'              => $bkp->cpf,
                        'nome'             => $bkp->nome,
                        'data_nascimento'  => $bkp->data_nascimento,
                        'fone1'            => $bkp->fone1,
                        'classe_fone1'     => $bkp->classe_fone1,
                        'fone2'            => $bkp->fone2,
                        'classe_fone2'     => $bkp->classe_fone2,
                        'fone3'            => $bkp->fone3,
                        'classe_fone3'     => $bkp->classe_fone3,
                        'fone4'            => $bkp->fone4,
                        'classe_fone4'     => $bkp->classe_fone4,
                        'consulta'         => $bkp->consulta,
                        'data_atualizacao' => $bkp->data_atualizacao,
                        'saldo'            => $bkp->saldo,
                        'libera'           => $bkp->libera,
                        'updated_at'       => $now,
                    ];
                }

                if (empty($payload)) {
                    return;
                }

                DB::table('leads')->upsert(
                    $payload,
                    ['id'],
                    [
                        'cpf',
                        'nome',
                        'data_nascimento',
                        'fone1',
                        'classe_fone1',
                        'fone2',
                        'classe_fone2',
                        'fone3',
                        'classe_fone3',
                        'fone4',
                        'classe_fone4',
                        'consulta',
                        'data_atualizacao',
                        'saldo',
                        'libera',
                        'updated_at',
                    ]
                );
            }, 'id');
    }

    private function rollbackInsertedContracts(int $jobId, int $chunkSize): void
    {
        LeadContractBackup::query()
            ->where('import_job_id', $jobId)
            ->where('action', 'insert')
            ->select(['id', 'lead_contract_id'])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows): void {
                $contractIds = $rows->pluck('lead_contract_id')->filter()->unique()->values()->all();
                if (!empty($contractIds)) {
                    LeadContract::whereIn('id', $contractIds)->delete();
                }
            }, 'id');
    }

    private function rollbackOrphanVendors(int $jobId, int $chunkSize): void
    {
        VendorBackup::query()
            ->where('import_job_id', $jobId)
            ->select(['id', 'vendor_id'])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows): void {
                $vendorIds = $rows->pluck('vendor_id')->filter()->unique()->values()->all();
                if (!empty($vendorIds)) {
                    Vendor::whereIn('id', $vendorIds)
                        ->doesntHave('contracts')
                        ->delete();
                }
            }, 'id');
    }
}
