<?php

namespace App\Modules\Presenca\Models;

use Illuminate\Database\Eloquent\Model;

class PresencaConsultJob extends Model
{
    protected $table = 'presenca_consult_jobs';

    protected $fillable = [
        'user_id',
        'title',
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
        'cancel_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'canceled_at' => 'datetime',
        'spool_bytes' => 'integer',
        'total_cpfs' => 'integer',
        'success_count' => 'integer',
        'policy_declined_count' => 'integer',
        'fail_count' => 'integer',
    ];

    public function getHasFileAttribute(): bool
    {
        return !empty($this->file_path) && !empty($this->file_disk);
    }
}
