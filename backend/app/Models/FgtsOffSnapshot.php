<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FgtsOffSnapshot extends Model
{
    protected $table = 'fgts_off_snapshots';

    protected $primaryKey = 'cpf';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // continuamos a gerenciar updated_at manualmente

    protected $fillable = [
        'cpf',
        'lead_id',
        'situacao',
        'authorized',
        'job_id',
        'raw_meta',
        'updated_at', // passa a ser o "consultado em"
    ];

    protected $casts = [
        'authorized' => 'boolean',
        'updated_at' => 'datetime',
        'raw_meta'   => 'array',
    ];
}
