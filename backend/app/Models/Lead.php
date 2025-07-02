<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'origem_cadastro',
        'data_importacao_cadastro',
        'consulta',
        'data_atualizacao',
        'saldo',
        'libera',
    ];

    protected $casts = [
        'data_nascimento' => 'date',
        'data_importacao_cadastro' => 'datetime',
        'data_atualizacao' => 'datetime',
        'saldo' => 'decimal:2',
        'libera' => 'decimal:2',
    ];

    public function contracts(): HasMany
    {
        return $this->hasMany(LeadContract::class);
    }
}