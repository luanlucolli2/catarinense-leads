<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'file_name',
        'file_path',
        'origin',
        'status',
        'started_at',
        'finished_at',
        'total_rows',       //  🆕
        'processed_rows',   //  🆕
    ];

    protected $casts = [
        'started_at'     => 'datetime',
        'finished_at'    => 'datetime',
        'total_rows'     => 'integer',
        'processed_rows' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }
}
