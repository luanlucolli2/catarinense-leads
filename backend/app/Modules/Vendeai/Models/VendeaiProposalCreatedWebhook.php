<?php

namespace App\Modules\Vendeai\Models;

use Illuminate\Database\Eloquent\Model;

class VendeaiProposalCreatedWebhook extends Model
{
    protected $table = 'vendeai_proposal_created_webhooks';

    protected $fillable = [
        'received_at',
        'account_id',
        'chat_id',
        'chat_product',
        'chat_stage',
        'contact_name',
        'contact_phone',
        'contact_email',
        'contact_cpf',
        'contact_birth_date',
        'contact_mother_name',
        'session_campaign',
        'session_inbox_phone_number',
        'session_product_being_processed',
        'tags',
        'proposal_id',
        'proposal_number',
        'proposal_status',
        'bank',
        'proposal_product',
        'liquid_value',
        'gross_value',
        'number_of_payments',
        'installment_value',
        'table_name',
        'table_id',
        'formalization_link',
        'raw_payload',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'contact_birth_date' => 'date',
        'tags' => 'array',
        'liquid_value' => 'decimal:2',
        'gross_value' => 'decimal:2',
        'number_of_payments' => 'integer',
        'installment_value' => 'decimal:2',
        'raw_payload' => 'array',
    ];
}
