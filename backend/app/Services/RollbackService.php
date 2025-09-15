<?php

namespace App\Services;

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
        DB::transaction(function () use ($job) {
            // -----------------------------------------------
            // 0) Descobrir leads que foram INSERIDOS no job
            // -----------------------------------------------
            $insertedByBackup = LeadBackup::where('import_job_id', $job->id)
                ->where('was_new', true)
                ->pluck('lead_id')
                ->all();

            // fallback: caso exista algum lead "insert" sem backup por algum motivo
            $insertedByPivot = LeadImport::where('import_job_id', $job->id)
                ->where('action', 'insert')
                ->pluck('lead_id')
                ->all();

            $leadIdsToDelete = array_values(array_unique(array_merge($insertedByBackup, $insertedByPivot)));

            // -----------------------------------------------
            // 1) Remover dependências dos leads inseridos
            //    (ordem importa p/ não violar FKs)
            // -----------------------------------------------
            if (!empty($leadIdsToDelete)) {
                // 1.1) remover pivot do próprio job (e de outros por segurança, se existirem)
                LeadImport::whereIn('lead_id', $leadIdsToDelete)->delete();

                // 1.2) remover contratos associados a esses leads
                LeadContract::whereIn('lead_id', $leadIdsToDelete)->delete();
            }

            // -----------------------------------------------
            // 2) Deletar leads inseridos
            // -----------------------------------------------
            if (!empty($leadIdsToDelete)) {
                Lead::whereIn('id', $leadIdsToDelete)->delete();
            }

            // -----------------------------------------------
            // 3) Restaurar dados dos leads ATUALIZADOS
            // -----------------------------------------------
            $backups = LeadBackup::where('import_job_id', $job->id)
                ->where('was_new', false)
                ->get();

            foreach ($backups as $bkp) {
                Lead::whereKey($bkp->lead_id)->update([
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
                    'updated_at'       => now(),
                ]);
            }

            // -----------------------------------------------
            // 4) Remover contratos inseridos (de leads pré-existentes)
            // -----------------------------------------------
            $contractIdsToDelete = LeadContractBackup::where('import_job_id', $job->id)
                ->where('action', 'insert')
                ->pluck('lead_contract_id')
                ->all();

            if (!empty($contractIdsToDelete)) {
                LeadContract::whereIn('id', $contractIdsToDelete)->delete();
            }

            // -----------------------------------------------
            // 5) Remover vendors criados no job que ficaram sem contratos
            // -----------------------------------------------
            $vendorIds = VendorBackup::where('import_job_id', $job->id)
                ->pluck('vendor_id')
                ->all();

            if (!empty($vendorIds)) {
                Vendor::whereIn('id', $vendorIds)
                    ->doesntHave('contracts')
                    ->delete();
            }

            // -----------------------------------------------
            // 6) Marcar job revertido e limpar resíduos
            // -----------------------------------------------
            $job->update([
                'status'         => 'revertido',
                'rolled_back_at' => now(),
            ]);

            // Limpa registros de pivot e backups do job (restante)
            LeadImport::where('import_job_id', $job->id)->delete();
            LeadBackup::where('import_job_id', $job->id)->delete();
            LeadContractBackup::where('import_job_id', $job->id)->delete();
            VendorBackup::where('import_job_id', $job->id)->delete();
        });
    }
}
