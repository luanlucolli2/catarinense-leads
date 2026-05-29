<?php

namespace App\Modules\Vendeai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendeaiProposalCreatedWebhook extends Model
{
    protected $table = 'vendeai_newcorban_proposal_attempts';

    protected $fillable = [
        'vendeai_lead_id',
        'received_at',
        'raw_payload',
        'newcorban_request_payload',
        'newcorban_response_status',
        'newcorban_response_body',
        'newcorban_proposta_id',
        'newcorban_cliente_id',
        'newcorban_sent_at',
        'newcorban_error',
    ];

    protected $casts = [
        'vendeai_lead_id' => 'integer',
        'received_at' => 'datetime',
        'raw_payload' => 'array',
        'newcorban_request_payload' => 'array',
        'newcorban_response_status' => 'integer',
        'newcorban_response_body' => 'array',
        'newcorban_sent_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(VendeaiLead::class, 'vendeai_lead_id');
    }
}
