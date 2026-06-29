<?php

namespace App\Modules\Uy3\Models;

use Illuminate\Database\Eloquent\Model;

class Uy3Snapshot extends Model
{
    protected $table = 'uy3_snapshots';

    protected $primaryKey = 'cpf';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'cpf',
        'type_webhook',
        'status',
        'data_admissao',
        'valor_liberado',
        'numero_parcelas',
        'codigo_requisicao',
        'margem_disponivel',
        'elegivel_emprestimo',
        'numero_inscricao_empregador',
        'pessoa_exposta_politicamente_codigo',
        'data_hora_validade_solicitacao',
        'is_mei',
        'active_fgts_debts',
        'all_branch_employees',
        'is_judicial_recovery',
        'updated_at',
    ];

    protected $casts = [
        'data_admissao' => 'date',
        'valor_liberado' => 'decimal:2',
        'numero_parcelas' => 'integer',
        'margem_disponivel' => 'decimal:2',
        'elegivel_emprestimo' => 'boolean',
        'pessoa_exposta_politicamente_codigo' => 'integer',
        'data_hora_validade_solicitacao' => 'datetime',
        'is_mei' => 'boolean',
        'active_fgts_debts' => 'array',
        'all_branch_employees' => 'array',
        'is_judicial_recovery' => 'boolean',
        'updated_at' => 'datetime',
    ];
}
