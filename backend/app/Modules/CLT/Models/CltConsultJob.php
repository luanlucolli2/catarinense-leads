<?php

namespace App\Modules\CLT\Models;

use Illuminate\Database\Eloquent\Model;

class CltConsultJob extends Model
{
    protected $table = 'clt_consult_jobs';

    protected $fillable = [
        'user_id','title','status','variant',
        'phase',
        'run_token',
        'phase2_total','phase2_attempt',
        'phase2_aprovado_count','phase2_nao_aprovado_count',
        'total_cpfs','elegivel_count','inelegivel_count','descartado_count','not_found_count','fail_count',
        'file_disk','file_path','file_name',
        'started_at','finished_at','canceled_at','paused_at','cancel_reason','scheduled_for',
        // spool
        'spool_path','spool_cpfs_path','spool_bytes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at'=> 'datetime',
        'canceled_at'=> 'datetime',
        'paused_at'=> 'datetime',
        'scheduled_for'=> 'datetime',
        'phase2_total' => 'integer',
        'phase2_attempt' => 'integer',
        'run_token' => 'integer',
        'phase2_aprovado_count' => 'integer',
        'phase2_nao_aprovado_count' => 'integer',
        'elegivel_count' => 'integer',
        'inelegivel_count' => 'integer',
        'descartado_count' => 'integer',
        'spool_bytes'=> 'integer',
    ];

    public function getHasFileAttribute(): bool
    {
        return !empty($this->file_path) && !empty($this->file_disk);
    }
}
