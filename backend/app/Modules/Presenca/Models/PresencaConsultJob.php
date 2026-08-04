<?php

namespace App\Modules\Presenca\Models;

use Illuminate\Database\Eloquent\Model;

class PresencaConsultJob extends Model
{
    protected $table = 'presenca_consult_jobs';

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
        'policy_declined_count',
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
        'paused_at',
        'cancel_reason',
        'scheduled_for',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'canceled_at' => 'datetime',
        'paused_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'spool_bytes' => 'integer',
        'total_cpfs' => 'integer',
        'success_count' => 'integer',
        'policy_declined_count' => 'integer',
        'fail_count' => 'integer',
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
