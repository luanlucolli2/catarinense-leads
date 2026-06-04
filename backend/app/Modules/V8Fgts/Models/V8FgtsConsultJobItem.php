<?php

namespace App\Modules\V8Fgts\Models;

use Illuminate\Database\Eloquent\Model;

class V8FgtsConsultJobItem extends Model
{
    public const STATE_QUEUED_START = 'queued_start';
    public const STATE_AWAITING_BALANCE = 'awaiting_balance';
    public const STATE_TERMINAL = 'terminal';

    protected $table = 'v8_fgts_consult_job_items';

    protected $fillable = [
        'job_id',
        'cpf',
        'state',
        'next_run_at',
        'accepted_at',
        'first_poll_at',
        'start_attempts',
        'poll_attempts',
        'last_message',
        'api_error_context',
        'last_phase2_snapshot',
        'result_row',
        'spool_written_at',
    ];

    protected $casts = [
        'next_run_at' => 'datetime',
        'accepted_at' => 'datetime',
        'first_poll_at' => 'datetime',
        'last_phase2_snapshot' => 'array',
        'result_row' => 'array',
        'spool_written_at' => 'datetime',
    ];
}
