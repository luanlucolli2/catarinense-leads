<?php

namespace App\Modules\SomaClt\Models;

use Illuminate\Database\Eloquent\Model;

class SomaCltConsultJob extends Model
{
    protected $table = 'soma_clt_consult_jobs';

    protected $appends = ['has_file'];

    protected $fillable = [
        'user_id', 'title', 'mode', 'executor', 'external_job_id', 'external_has_report',
        'status', 'phase', 'total_cpfs', 'success_count', 'policy_declined_count', 'fail_count',
        'phase1_pending_count', 'phase1_success_count', 'phase1_declined_count', 'phase1_errors_count',
        'phase2_success_count', 'phase2_declined_count', 'phase2_errors_count',
        'file_disk', 'file_path', 'file_name', 'started_at', 'finished_at', 'canceled_at',
        'paused_at', 'cancel_reason', 'scheduled_for',
    ];

    protected $casts = [
        'external_has_report' => 'boolean',
        'total_cpfs' => 'integer',
        'success_count' => 'integer',
        'policy_declined_count' => 'integer',
        'fail_count' => 'integer',
        'phase1_pending_count' => 'integer',
        'phase1_success_count' => 'integer',
        'phase1_declined_count' => 'integer',
        'phase1_errors_count' => 'integer',
        'phase2_success_count' => 'integer',
        'phase2_declined_count' => 'integer',
        'phase2_errors_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'canceled_at' => 'datetime',
        'paused_at' => 'datetime',
        'scheduled_for' => 'datetime',
    ];

    public function getHasFileAttribute(): bool
    {
        return (bool) $this->external_has_report;
    }
}
