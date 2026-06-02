<?php

namespace App\Modules\Vendeai\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class VendeaiLead extends Model
{
    protected $table = 'vendeai_leads';

    protected $fillable = [
        'account_id',
        'chat_id',
        'product_key',
        'last_event',
        'chat_product',
        'stage',
        'tags',
        'campaign',
        'inbox_phone_number',
        'product_being_processed',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_cpf',
        'customer_birth_date',
        'customer_mother_name',
        'simulation_product',
        'simulation_bank',
        'simulation_liquid_value',
        'simulation_number_of_payments',
        'simulation_installment_value',
        'simulation_monthly_fee',
        'simulation_table_name',
        'simulation_table_id',
        'simulation_best_liquid_value',
        'simulation_best_table_id',
        'simulation_table_details',
        'simulation_received_at',
        'proposal_id',
        'proposal_number',
        'proposal_status',
        'previous_proposal_status',
        'proposal_bank',
        'proposal_product',
        'proposal_liquid_value',
        'proposal_gross_value',
        'proposal_number_of_payments',
        'proposal_installment_value',
        'proposal_table_name',
        'proposal_table_id',
        'proposal_formalization_link',
        'proposal_created_at',
        'proposal_status_updated_at',
        'first_received_at',
        'last_received_at',
        'last_payload',
    ];

    protected $casts = [
        'tags' => 'array',
        'customer_birth_date' => 'date',
        'simulation_liquid_value' => 'decimal:2',
        'simulation_number_of_payments' => 'integer',
        'simulation_installment_value' => 'decimal:2',
        'simulation_monthly_fee' => 'decimal:4',
        'simulation_best_liquid_value' => 'decimal:2',
        'simulation_table_details' => 'array',
        'simulation_received_at' => 'datetime',
        'proposal_liquid_value' => 'decimal:2',
        'proposal_gross_value' => 'decimal:2',
        'proposal_number_of_payments' => 'integer',
        'proposal_installment_value' => 'decimal:2',
        'proposal_created_at' => 'datetime',
        'proposal_status_updated_at' => 'datetime',
        'first_received_at' => 'datetime',
        'last_received_at' => 'datetime',
        'last_payload' => 'array',
    ];

    public function proposalCreatedWebhooks(): HasMany
    {
        return $this->hasMany(VendeaiProposalCreatedWebhook::class, 'vendeai_lead_id');
    }
}
