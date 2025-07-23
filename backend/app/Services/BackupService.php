<?php

namespace App\Services;

use App\Models\ImportJob;
use App\Models\Backup\LeadBackup;
use App\Models\Backup\LeadContractBackup;
use App\Models\Backup\VendorBackup;
use App\Models\Lead;
use App\Models\LeadContract;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BackupService
{
    /** Tamanho do insert em lote – mantém compatível com chunk dos imports */
    public const CHUNK_SIZE = 1000;

    /* ---------------------------------------------------------------------
     | LEADS
     * --------------------------------------------------------------------*/

    /**
     * Cria backups **antes do updateOrCreate**.
     *
     * @param \Illuminate\Support\Collection<int,\App\Models\Lead> $existingLeads
     */
    public function bulkBackupLeads(Collection $existingLeads, ImportJob $job): void
    {
        // não há nada a fazer se a Collection vier vazia
        if ($existingLeads->isEmpty()) {
            return;
        }

        // Monta array de linhas p/ insert (mais rápido que ->create() dentro de loop)
        $rows = $existingLeads->map(fn (Lead $lead) => [
            'import_job_id'    => $job->id,
            'lead_id'          => $lead->id,
            'was_new'          => false,
            'cpf'              => $lead->cpf,
            'nome'             => $lead->nome,
            'data_nascimento'  => $lead->data_nascimento,
            'fone1'            => $lead->fone1,
            'classe_fone1'     => $lead->classe_fone1,
            'fone2'            => $lead->fone2,
            'classe_fone2'     => $lead->classe_fone2,
            'fone3'            => $lead->fone3,
            'classe_fone3'     => $lead->classe_fone3,
            'fone4'            => $lead->fone4,
            'classe_fone4'     => $lead->classe_fone4,
            'consulta'         => $lead->consulta,
            'data_atualizacao' => $lead->data_atualizacao,
            'saldo'            => $lead->saldo,
            'libera'           => $lead->libera,
            'created_at'       => now(),
            'updated_at'       => now(),
        ])->toArray();

        // Insert em fatias p/ não estourar limites de pacote
        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            LeadBackup::insert($chunk);
        }
    }

    /**
     * Marcar lead **novo** inserido no import para futura deleção.
     * Usa was_new = true, facilitando rollback.
     */
    public function backupNewLead(Lead $lead, ImportJob $job): void
    {
        LeadBackup::create([
            'import_job_id' => $job->id,
            'lead_id'       => $lead->id,
            'was_new'       => true,
            // campos mínimos – não precisamos snapshot porque será deletado
            'cpf'           => $lead->cpf,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /* ---------------------------------------------------------------------
     | CONTRATOS
     * --------------------------------------------------------------------*/

    /**
     * Grava contrato recém-criado para poder removê-lo no rollback.
     */
    public function backupInsertedContract(LeadContract $contract, ImportJob $job): void
    {
        LeadContractBackup::create([
            'import_job_id'    => $job->id,
            'lead_id'          => $contract->lead_id,
            'lead_contract_id' => $contract->id,
            'vendor_id'        => $contract->vendor_id,
            'data_contrato'    => $contract->data_contrato,
            'action'           => 'insert',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /* ---------------------------------------------------------------------
     | VENDORS
     * --------------------------------------------------------------------*/

    /**
     * Salva vendor **criado** durante o import (evita duplicar backup).
     */
    public function backupVendorIfNew(Vendor $vendor, ImportJob $job): void
    {
        VendorBackup::firstOrCreate(
            [
                'import_job_id' => $job->id,
                'vendor_id'     => $vendor->id,
            ],
            [
                'name'               => $vendor->name,
                'name_clean'         => $vendor->name_clean,
                'original_created_at'=> $vendor->created_at,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]
        );
    }

     public function purgeOldBackups(): void
    {
        // Desabilita verificações de FK (MySQL) para truncar sem erros
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        LeadBackup::truncate();
        LeadContractBackup::truncate();
        VendorBackup::truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
