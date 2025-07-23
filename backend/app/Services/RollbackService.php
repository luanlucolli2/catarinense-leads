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
     */
    public function rollback(ImportJob $job): void
    {
        DB::transaction(function () use ($job) {
            // 1. Deleta leads que foram *inseridos* nesse job
            $leadIdsToDelete = LeadImport::where('import_job_id', $job->id)
                ->where('action', 'insert')
                ->pluck('lead_id')
                ->all();

            if (!empty($leadIdsToDelete)) {
                Lead::whereIn('id', $leadIdsToDelete)->delete();
            }

            // 2. Restaura dados de leads *atualizados*
            $backups = LeadBackup::where('import_job_id', $job->id)->get();
            foreach ($backups as $bkp) {
                if (!$bkp->was_new) {
                    Lead::whereKey($bkp->lead_id)
                        ->update([
                            'cpf' => $bkp->cpf,
                            'nome' => $bkp->nome,
                            'data_nascimento' => $bkp->data_nascimento,
                            'fone1' => $bkp->fone1,
                            'classe_fone1' => $bkp->classe_fone1,
                            'fone2' => $bkp->fone2,
                            'classe_fone2' => $bkp->classe_fone2,
                            'fone3' => $bkp->fone3,
                            'classe_fone3' => $bkp->classe_fone3,
                            'fone4' => $bkp->fone4,
                            'classe_fone4' => $bkp->classe_fone4,
                            'consulta' => $bkp->consulta,
                            'data_atualizacao' => $bkp->data_atualizacao,
                            'saldo' => $bkp->saldo,
                            'libera' => $bkp->libera,
                            'updated_at' => now(),
                        ]);
                }
            }

            // 3. Remove contratos que foram *inseridos*
            $contractIdsToDelete = LeadContractBackup::where('import_job_id', $job->id)
                ->where('action', 'insert')
                ->pluck('lead_contract_id')
                ->all();

            if (!empty($contractIdsToDelete)) {
                LeadContract::whereIn('id', $contractIdsToDelete)->delete();
            }

            // 4. Remove vendors criados nesse job, desde que não tenham mais contratos
            $vendorIds = VendorBackup::where('import_job_id', $job->id)
                ->pluck('vendor_id')
                ->all();

            if (!empty($vendorIds)) {
                Vendor::whereIn('id', $vendorIds)
                    ->doesntHave('contracts')
                    ->delete();
            }

            // 5. Marca o job como rollback feito
            $job->update([
                'status' => 'revertido',   // 👍 novo
                'rolled_back_at' => now(),
            ]);
            
            // 6. Limpa registros de pivot e backups
            LeadImport::where('import_job_id', $job->id)->delete();
            LeadBackup::where('import_job_id', $job->id)->delete();
            LeadContractBackup::where('import_job_id', $job->id)->delete();
            VendorBackup::where('import_job_id', $job->id)->delete();
        });
    }
}
