<?php

namespace App\Modules\V8\Models;

use Illuminate\Database\Eloquent\Model;

class V8ConsultJob extends Model
{
    protected $table = 'v8_consult_jobs';

    protected $appends = ['has_file'];

    protected $fillable = [
        'user_id',
        'title',
        'executor',
        'external_job_id',
        'external_has_report',
        'status',
        'phase',
        'total_cpfs',
        'success_count',
        'nao_elegivel_count',
        'fail_count',
        'file_disk',
        'file_path',
        'file_name',
        'started_at',
        'finished_at',
        'canceled_at',
        'paused_at',
        'cancel_reason',
        'scheduled_for',
        'spool_path',
        'spool_inputs_path',
        'spool_bytes',
        'reuse_recent_consults',
        'reuse_recent_consults_days',
        'phase1_submitted_count',
        'phase1_not_eligible_count',
        'phase1_errors_count',
        'phase2_approved_count',
        'phase2_not_approved_count',
        'phase2_errors_count',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'canceled_at' => 'datetime',
        'paused_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'spool_bytes' => 'integer',
        'reuse_recent_consults' => 'boolean',
        'reuse_recent_consults_days' => 'integer',
        'external_has_report' => 'boolean',
    ];

    public function getHasFileAttribute(): bool
    {
        if ($this->executor === 'api') {
            return (bool) $this->external_has_report;
        }

        return !empty($this->file_path) && !empty($this->file_disk);
    }
}
