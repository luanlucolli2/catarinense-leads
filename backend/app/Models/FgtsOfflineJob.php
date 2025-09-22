<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FgtsOfflineJob extends Model
{
    protected $table = 'fgts_off_consult_jobs';

    protected $fillable = [
        'user_id','title','status',
        'total_cpfs','success_count','not_authorized_count','fail_count',
        'file_disk','file_path','file_name',
        'started_at','finished_at',
        'canceled_at','cancel_reason',
        'scheduled_for','scheduled_until',

        // prévia (arquivo)
        'preview_disk','preview_path','preview_name','preview_updated_at','preview_dirty',

        // prévia (estado/telemetria)
        'preview_status','preview_requested_at','preview_started_at','preview_finished_at',
        'preview_size_bytes','preview_rows','preview_error',

        // spool
        'spool_path','spool_cpfs_path','spool_bytes',
    ];

    protected $casts = [
        'started_at'            => 'datetime',
        'finished_at'           => 'datetime',
        'canceled_at'           => 'datetime',
        'scheduled_for'         => 'datetime',
        'scheduled_until'       => 'datetime',

        'preview_updated_at'    => 'datetime',
        'preview_requested_at'  => 'datetime',
        'preview_started_at'    => 'datetime',
        'preview_finished_at'   => 'datetime',

        'preview_dirty'         => 'boolean',
        'spool_bytes'           => 'integer',
        'preview_rows'          => 'integer',
        'preview_size_bytes'    => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Tem arquivo final pronto? */
    public function getHasFileAttribute(): bool
    {
        return !empty($this->file_path) && !empty($this->file_disk);
    }

    /** Pode ser cancelado? */
    public function getIsCancelableAttribute(): bool
    {
        return in_array($this->status, ['pendente','em_progresso','agendado'], true);
    }

    /** Prévia disponível (arquivo pronto)? */
    public function getHasPreviewAttribute(): bool
    {
        return !empty($this->preview_path) && !empty($this->preview_disk);
    }
}
