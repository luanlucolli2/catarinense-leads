<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CltConsultJob extends Model
{
    protected $fillable = [
        'user_id','title','status',
        'total_cpfs','success_count','fail_count',
        'file_disk','file_path','file_name',
        'started_at','finished_at',
        'canceled_at','cancel_reason',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
        'canceled_at'  => 'datetime',
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
}
