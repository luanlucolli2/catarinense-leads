<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankAuthorization extends Model
{
    use HasFactory;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_DENIED     = 'denied';
    public const STATUS_ERROR      = 'error';
    public const STATUS_TIMED_OUT  = 'timed_out';

    protected $fillable = [
        'tracking_id',
        'connection_token', // ✅ desnormalizado para lookup rápido
        'bank',
        'step',
        'cpf',
        'phone',
        'link',
        'external_id',
        'status',
        'last_status_payload',
        'last_checked_at',
        'authorized_at',
        'failed_at',
        'error_message',
    ];

    protected $casts = [
        'last_status_payload' => 'array',
        'last_checked_at'     => 'datetime',
        'authorized_at'       => 'datetime',
        'failed_at'           => 'datetime',
    ];

    public function triage()
    {
        return $this->belongsTo(InovachatTriage::class, 'tracking_id', 'tracking_id');
    }
}
