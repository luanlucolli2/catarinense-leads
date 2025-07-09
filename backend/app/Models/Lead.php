<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany; // 1. Importar a classe


class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'cpf',
        'nome',
        'data_nascimento',
        'fone1',
        'classe_fone1',
        'fone2',
        'classe_fone2',
        'fone3',
        'classe_fone3',
        'fone4',
        'classe_fone4',
        'consulta',
        'data_atualizacao',
        'saldo',
        'libera',
    ];

    protected $casts = [
        'data_nascimento' => 'date',
        'data_atualizacao' => 'datetime',
        // As linhas de 'saldo' e 'libera' foram REMOVIDAS daqui
    ];

    public function contracts(): HasMany
    {
        return $this->hasMany(LeadContract::class);
    }

    public function importJobs(): BelongsToMany
    {
        // A MUDANÇA ESTÁ AQUI:
        // Removemos o ->withTimestamps() pois a tabela pivot só tem created_at,
        // que já é gerenciado pelo banco de dados (useCurrent()).
        return $this->belongsToMany(ImportJob::class, 'lead_imports')
            ->withPivot('action');
    }
}