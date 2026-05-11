<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MercantilSnapshot extends Model
{
    protected $table = 'mercantil_snapshots';

    protected $primaryKey = 'cpf';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'cpf',
        'nome',
        'data_nascimento',
        'status',
        'mensagem_erro',
        'data_hora_origem',
        'valor_financiado',
        'valor_iof',
        'data_primeiro_vencimento',
        'valor_emprestimo',
        'quantidade_parcelas',
        'valor_liberado',
        'taxa_juros_mes',
        'valor_parcela',
        'job_id',
        'updated_at',
    ];

    protected $casts = [
        'data_nascimento' => 'date',
        'data_hora_origem' => 'datetime',
        'data_primeiro_vencimento' => 'date',
        'valor_financiado' => 'decimal:2',
        'valor_iof' => 'decimal:2',
        'valor_emprestimo' => 'decimal:2',
        'quantidade_parcelas' => 'integer',
        'valor_liberado' => 'decimal:2',
        'taxa_juros_mes' => 'decimal:4',
        'valor_parcela' => 'decimal:2',
        'updated_at' => 'datetime',
    ];
}
