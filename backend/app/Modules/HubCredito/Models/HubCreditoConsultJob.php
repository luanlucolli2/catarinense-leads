<?php

namespace App\Modules\HubCredito\Models;

use Illuminate\Database\Eloquent\Model;

class HubCreditoConsultJob extends Model
{
    protected $table = 'hubcredito_consult_jobs';

    protected $fillable = [
        'user_id',
        'title',
        'executor',
        'external_job_id',
        'external_has_report',
        'status',
        'phase',
        'total_cpfs',
        'aprovado_count',
        'nao_aprovado_count',
        'fail_count',
        'file_disk',
        'file_path',
        'file_name',
        'spool_path',
        'spool_inputs_path',
        'spool_bytes',
        'started_at',
        'finished_at',
        'canceled_at',
        'cancel_reason',
        'scheduled_for',
        'paused_at',
        'phase1_submitted_count',
        'phase1_not_approved_count',
        'phase1_fail_count',
        'phase2_approved_count',
        'phase2_not_approved_count',
        'phase2_fail_count',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'canceled_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'paused_at' => 'datetime',
        'external_has_report' => 'boolean',
        'spool_bytes' => 'integer',
        'total_cpfs' => 'integer',
        'aprovado_count' => 'integer',
        'nao_aprovado_count' => 'integer',
        'fail_count' => 'integer',
        'phase1_submitted_count' => 'integer',
        'phase1_not_approved_count' => 'integer',
        'phase1_fail_count' => 'integer',
        'phase2_approved_count' => 'integer',
        'phase2_not_approved_count' => 'integer',
        'phase2_fail_count' => 'integer',
    ];

    public function getHasFileAttribute(): bool
    {
        return !empty($this->file_path) && !empty($this->file_disk);
    }
}
