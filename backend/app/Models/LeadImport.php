<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class LeadImport extends Pivot
{
    protected $table = 'lead_imports';

    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'lead_id',
        'import_job_id',
        'action',
        'created_at',
    ];
}
