<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CltSnapshot extends Model
{
    protected $table = 'clt_snapshots';

    protected $primaryKey = 'cpf';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // gerenciamos updated_at manualmente

    protected $fillable = [
        'cpf',
        'lead_id',
        'nome',
        'elegivel',
        'data_nascimento',
        'idade',
        'sexo',
        'data_admissao',
        'meses_admissao',
        'valor_renda',
        'valor_base_margem',
        'margem_disponivel',
        'valor_max_prestacao',
        'categoria_trabalhador_codigo',
        'inicio_atividade_empregador',
        'qtd_emprestimos_ativos_suspensos',
        'emprestimos_legados',
        'not_found',
        'job_id',
        'updated_at',
    ];

    protected $casts = [
        'elegivel'                          => 'boolean',
        'data_nascimento'                   => 'date',
        'idade'                             => 'integer',
        'data_admissao'                     => 'date',
        'meses_admissao'                    => 'integer',
        'valor_renda'                       => 'decimal:2',
        'valor_base_margem'                 => 'decimal:2',
        'margem_disponivel'                 => 'decimal:2',
        'valor_max_prestacao'               => 'decimal:2',
        'inicio_atividade_empregador'       => 'date',
        'qtd_emprestimos_ativos_suspensos'  => 'integer',
        'emprestimos_legados'               => 'integer',
        'not_found'                         => 'boolean',
        'updated_at'                        => 'datetime',
    ];
}
