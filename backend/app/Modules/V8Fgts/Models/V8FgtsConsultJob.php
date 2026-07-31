<?php

namespace App\Modules\V8Fgts\Models;

use Illuminate\Database\Eloquent\Model;

class V8FgtsConsultJob extends Model
{
    protected $table = 'v8_fgts_consult_jobs';

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
        'spool_path',
        'spool_cpfs_path',
        'spool_bytes',
        'started_at',
        'finished_at',
        'canceled_at',
        'cancel_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'canceled_at' => 'datetime',
        'spool_bytes' => 'integer',
        'external_has_report' => 'boolean',
    ];

    public function getHasFileAttribute(): bool
    {
        if ($this->executor === 'api') {
            return $this->external_has_report;
        }

        return !empty($this->file_path) && !empty($this->file_disk);
    }
}
