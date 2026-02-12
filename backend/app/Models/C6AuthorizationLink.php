<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class C6AuthorizationLink extends Model
{
    public const STATUS_ACTIVE = 'ativo';
    public const STATUS_EXPIRED = 'expirado';

    protected $fillable = [
        'user_id',
        'cpf',
        'nome_cliente',
        'link',
        'generated_at',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    public static function markExpired(?int $userId = null): int
    {
        $query = static::query()
            ->where('expires_at', '<=', now())
            ->where('status', '!=', self::STATUS_EXPIRED);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query->update([
            'status' => self::STATUS_EXPIRED,
            'updated_at' => now(),
        ]);
    }
}
