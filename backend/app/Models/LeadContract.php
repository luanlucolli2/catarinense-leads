<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadContract extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'data_contrato',
    ];

    protected $casts = [
        'data_contrato' => 'date',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}