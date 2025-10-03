<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FgtsOffSnapshot extends Model
{
    protected $table = 'fgts_off_snapshots';

    protected $primaryKey = 'cpf';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // usamos updated_at manual

    protected $fillable = [
        'cpf',
        'lead_id',
        'situacao',
        'authorized',
        'consultado_em',
        'job_id',
        'raw_meta',
        'updated_at',
    ];

    protected $casts = [
        'authorized'    => 'boolean',
        'consultado_em' => 'datetime',
        'updated_at'    => 'datetime',
        'raw_meta'      => 'array',
    ];
}
