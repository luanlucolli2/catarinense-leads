<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CltConsultJob extends Model
{
    protected $fillable = [
        'user_id','title','status',
        'total_cpfs','success_count','fail_count','not_found_count',
        'file_disk','file_path','file_name',
        'started_at','finished_at',
        'canceled_at','cancel_reason',
        // prévia
        'preview_disk','preview_path','preview_name','preview_updated_at','preview_dirty',
        // spool
        'spool_path','spool_cpfs_path','spool_bytes',
    ];

    protected $casts = [
        'started_at'         => 'datetime',
        'finished_at'        => 'datetime',
        'canceled_at'        => 'datetime',
        'preview_updated_at' => 'datetime',
        'preview_dirty'      => 'boolean',
        'spool_bytes'        => 'integer',
    ];

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function getHasFileAttribute(): bool {
        return !empty($this->file_path) && !empty($this->file_disk);
    }

    /** Conveniência: pode ser cancelado? */
    public function getIsCancelableAttribute(): bool
    {
        return in_array($this->status, ['pendente','em_progresso'], true);
    }

    /** Conveniência: tem prévia disponível? */
    public function getHasPreviewAttribute(): bool
    {
        return !empty($this->preview_path) && !empty($this->preview_disk);
    }
}
