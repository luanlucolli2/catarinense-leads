<?php

namespace App\Modules\FactaCLT\Models;

use Illuminate\Database\Eloquent\Model;

class FactaCltSnapshot extends Model
{
    protected $table = 'facta_clt_snapshots';

    protected $primaryKey = 'cpf';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // gerenciamos updated_at/consulted_at manualmente

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
        'matricula',
        'valor_renda',
        'valor_base_margem',
        'margem_disponivel',
        'valor_max_prestacao',
        'categoria_trabalhador_codigo',
        'inicio_atividade_empregador',
        'meses_empresa_empregador',
        'qtd_emprestimos_ativos_suspensos',
        'emprestimos_legados',
        'politica_credito_aprovado',
        'politica_credito_mensagem',
        'politica_credito_valor_maximo_disponivel',
        'politica_credito_prazo_maximo_disponivel',
        'politica_credito_data_consulta',
        'politica_credito_tabela_aprovada',
        'not_found',
        'job_id',
        'updated_at',    // última atualização do DADO na origem
        'consulted_at',  // quando NÓS consultamos
    ];

    protected $casts = [
        'elegivel' => 'boolean',
        'data_nascimento' => 'date',
        'idade' => 'integer',
        'data_admissao' => 'date',
        'meses_admissao' => 'integer',
        'valor_renda' => 'decimal:2',
        'valor_base_margem' => 'decimal:2',
        'margem_disponivel' => 'decimal:2',
        'valor_max_prestacao' => 'decimal:2',
        'inicio_atividade_empregador' => 'date',
        'meses_empresa_empregador' => 'integer',
        'qtd_emprestimos_ativos_suspensos' => 'integer',
        'emprestimos_legados' => 'boolean',
        'politica_credito_aprovado' => 'boolean',
        'politica_credito_valor_maximo_disponivel' => 'decimal:2',
        'politica_credito_prazo_maximo_disponivel' => 'integer',
        'politica_credito_data_consulta' => 'datetime',
        'not_found' => 'boolean',
        'updated_at' => 'datetime',
        'consulted_at' => 'datetime',
    ];
}
