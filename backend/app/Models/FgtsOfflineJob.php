<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FgtsOfflineJob extends Model
{
    protected $table = 'fgts_off_consult_jobs';

    protected $fillable = [
        'user_id',
        'title',
        'status',
        'total_cpfs',
        'success_count',
        'not_authorized_count',
        'fail_count',
        'file_disk',
        'file_path',
        'file_name',
        'started_at',
        'finished_at',
        'canceled_at',
        'cancel_reason',
        'scheduled_for',
        'scheduled_until',
        'spool_path',
        'spool_cpfs_path',
        'spool_bytes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'canceled_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'scheduled_until' => 'datetime',
        'spool_bytes' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getHasFileAttribute(): bool
    {
        return !empty($this->file_path) && !empty($this->file_disk);
    }

    public function getIsCancelableAttribute(): bool
    {
        return in_array($this->status, ['pendente', 'em_progresso', 'agendado'], true);
    }
}
