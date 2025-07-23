<?php

namespace App\Models\Backup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Vendor;
use App\Models\ImportJob;

class VendorBackup extends Model
{
    protected $table = 'vendor_backups';
    public $timestamps = true;
    protected $guarded = [];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }
}
