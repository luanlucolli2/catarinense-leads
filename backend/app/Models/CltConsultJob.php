<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CltConsultJob extends Model
{
    protected $table = 'clt_consult_jobs';

    protected $fillable = [
        'user_id','title','status','variant',
        'total_cpfs','success_count','not_found_count','fail_count',
        'file_disk','file_path','file_name',
        'started_at','finished_at','paused_at','canceled_at','cancel_reason',

        // spool
        'spool_path','spool_cpfs_path','spool_bytes',

        // prévia (arquivo)
        'preview_disk','preview_path','preview_name','preview_updated_at','preview_dirty',

        // prévia (estado/telemetria)
        'preview_status','preview_requested_at','preview_started_at','preview_finished_at',
        'preview_size_bytes','preview_rows','preview_error',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at'=> 'datetime',
        'paused_at'  => 'datetime',
        'canceled_at'=> 'datetime',

        'preview_updated_at'   => 'datetime',
        'preview_requested_at' => 'datetime',
        'preview_started_at'   => 'datetime',
        'preview_finished_at'  => 'datetime',

        'preview_dirty'        => 'boolean',
        'spool_bytes'          => 'integer',
        'preview_rows'         => 'integer',
        'preview_size_bytes'   => 'integer',
    ];

    public function getHasFileAttribute(): bool
    {
        return !empty($this->file_path) && !empty($this->file_disk);
    }

    public function getHasPreviewAttribute(): bool
    {
        return !empty($this->preview_path) && !empty($this->preview_disk);
    }
}
