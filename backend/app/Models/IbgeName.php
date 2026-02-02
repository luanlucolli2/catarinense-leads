<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IbgeName extends Model
{
    // Desabilitamos timestamps para otimizar inserts e storage
    public $timestamps = false;

    protected $fillable = [
        'name',
        'gender',
    ];

    // Cast para garantir performance e tipo
    protected $casts = [
        'gender' => 'string',
    ];
}