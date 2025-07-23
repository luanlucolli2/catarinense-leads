<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Backup\LeadBackup;
use App\Models\Backup\VendorBackup;
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
        'rolled_back_at',      // ✅ agora permitido
        'total_rows',
        'processed_rows',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'rolled_back_at' => 'datetime',   // ✅ cast certo
        'total_rows' => 'integer',
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

    public function isLastFinished(): bool
    {
        $lastId = self::whereNull('rolled_back_at')
            ->where('status', 'concluido')
            ->max('id');

        return $this->id === $lastId;
    }

    /* Relacionamentos com backups (opcionais p/ relatórios) */
    public function leadBackups(): HasMany
    {
        return $this->hasMany(LeadBackup::class);
    }

    public function vendorBackups(): HasMany
    {
        return $this->hasMany(VendorBackup::class);
    }
}
