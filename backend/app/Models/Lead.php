<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'cpf', 'nome', 'data_nascimento', 'fone1', 'classe_fone1',
        'fone2', 'classe_fone2', 'fone3', 'classe_fone3', 'fone4', 'classe_fone4',
        'consulta', 'data_atualizacao', 'saldo', 'libera',
    ];

    protected $casts = [
        'data_nascimento' => 'date',
        'data_atualizacao' => 'datetime',
    ];

    public function contracts(): HasMany
    {
        return $this->hasMany(LeadContract::class);
    }

    public function importJobs(): BelongsToMany
    {
        // CORREÇÃO FINAL: Usamos o nome completo da tabela 'lead_imports' para a ordenação.
        return $this->belongsToMany(ImportJob::class, 'lead_imports')
            ->withPivot('action', 'created_at')
            ->orderBy('lead_imports.created_at', 'asc');
    }
}