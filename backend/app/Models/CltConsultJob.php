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
        'started_at','finished_at','canceled_at','cancel_reason',
        // spool
        'spool_path','spool_cpfs_path','spool_bytes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at'=> 'datetime',
        'canceled_at'=> 'datetime',
        'spool_bytes'=> 'integer',
    ];

    public function getHasFileAttribute(): bool
    {
        return !empty($this->file_path) && !empty($this->file_disk);
    }
}
