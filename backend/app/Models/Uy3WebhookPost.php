<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Uy3WebhookPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'payload',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public $timestamps = false;
}
