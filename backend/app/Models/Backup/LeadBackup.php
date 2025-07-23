<?php

namespace App\Models\Backup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Lead;
use App\Models\ImportJob;

class LeadBackup extends Model
{
    /* tabela e PK */
    protected $table = 'lead_backups';

    /* timestamps TRUE → created_at / updated_at funcionam */
    public $timestamps = true;

    /* todos os campos liberados (mais simples em backups) */
    protected $guarded = [];

    /* casts idênticos ao Lead original */
    protected $casts = [
        'data_nascimento' => 'date',
        'data_atualizacao' => 'datetime',
        'was_new'          => 'boolean',
    ];

    /* --------- relationships ---------- */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }
}
