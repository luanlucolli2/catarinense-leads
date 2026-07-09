<?php

namespace App\Modules\HubCredito\Models;

use Illuminate\Database\Eloquent\Model;

class HubCreditoConsultJob extends Model
{
    protected $table = 'hubcredito_consult_jobs';

    protected $fillable = [
        'user_id',
        'title',
        'status',
        'phase',
        'total_cpfs',
        'aprovado_count',
        'nao_aprovado_count',
        'pendencia_count',
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
        'aprovado_count' => 'integer',
        'nao_aprovado_count' => 'integer',
        'pendencia_count' => 'integer',
    ];

    public function getHasFileAttribute(): bool
    {
        return !empty($this->file_path) && !empty($this->file_disk);
    }
}
