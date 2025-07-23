<?php

namespace App\Models\Backup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Lead;
use App\Models\Vendor;
use App\Models\ImportJob;

class LeadContractBackup extends Model
{
    protected $table = 'lead_contract_backups';
    public $timestamps = true;
    protected $guarded = [];

    protected $casts = [
        'data_contrato' => 'date',
    ];

    /* ---------- relationships --------- */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }
}
