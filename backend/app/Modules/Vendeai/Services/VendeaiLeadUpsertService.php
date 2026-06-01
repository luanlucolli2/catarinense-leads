<?php

namespace App\Modules\Vendeai\Services;

use App\Modules\Vendeai\Models\VendeaiLead;
use App\Support\Cpf;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

class VendeaiLeadUpsertService
{
    private const EVENTS = [
        'stage_updated',
        'tag_updated',
        'proposal_created',
        'proposal_status_updated',
        'simulation_offered',
    ];

    public function upsert(array $payload, string $event): ?VendeaiLead
    {
        if (! in_array($event, self::EVENTS, true)) {
            return null;
        }

        $accountId = $this->stringOrNull(data_get($payload, 'chat_summary.account_id'), 50);
        $chatId = $this->stringOrNull(data_get($payload, 'chat_summary.chat_id'), 100);

        if ($accountId === null || $chatId === null) {
            return null;
        }

        $now = now();
        $lead = VendeaiLead::firstOrNew([
            'account_id' => $accountId,
            'chat_id' => $chatId,
        ]);

        $attributes = array_merge(
            $this->baseAttributes($payload, $event, $now),
            $this->eventAttributes($payload, $event, $now),
        );

        if (! $lead->exists || $lead->first_received_at === null) {
            $attributes['first_received_at'] = $now;
        }

        $lead->fill($attributes);

        try {
            $lead->save();
        } catch (QueryException) {
            $lead = VendeaiLead::query()
                ->where('account_id', $accountId)
                ->where('chat_id', $chatId)
                ->first();

            if ($lead === null) {
                return null;
            }

            unset($attributes['first_received_at']);
            $lead->fill($attributes);
            $lead->save();
        }

        return $lead;
    }

    private function baseAttributes(array $payload, string $event, Carbon $now): array
    {
        return [
            'last_event' => $this->stringOrNull($event, 50),
            'chat_product' => $this->stringOrNull(data_get($payload, 'chat_summary.product'), 30),
            'stage' => $this->stringOrNull(data_get($payload, 'chat_summary.stage'), 100),
            'tags' => is_array(data_get($payload, 'chat_summary.tags')) ? data_get($payload, 'chat_summary.tags') : null,
            'campaign' => $this->stringOrNull(data_get($payload, 'chat_summary.details.session.campaign'), 150),
            'inbox_phone_number' => $this->stringOrNull(data_get($payload, 'chat_summary.details.session.inbox_phone_number'), 30),
            'product_being_processed' => $this->stringOrNull(data_get($payload, 'chat_summary.details.session.product_being_processed'), 30),
            'customer_name' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.name'), 255),
            'customer_phone' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.phone'), 30),
            'customer_email' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.email'), 255),
            'customer_cpf' => Cpf::normalize($this->stringOrNull(data_get($payload, 'chat_summary.details.contact.cpf'))),
            'customer_birth_date' => $this->dateOrNull(data_get($payload, 'chat_summary.details.contact.birth_date')),
            'customer_mother_name' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.mother_name'), 255),
            'last_received_at' => $now,
            'last_payload' => $payload,
        ];
    }

    private function eventAttributes(array $payload, string $event, Carbon $now): array
    {
        return match ($event) {
            'simulation_offered' => $this->simulationAttributes($payload, $now),
            'proposal_created' => $this->proposalCreatedAttributes($payload, $now),
            'proposal_status_updated' => $this->proposalStatusAttributes($payload, $now),
            default => [],
        };
    }

    private function simulationAttributes(array $payload, Carbon $now): array
    {
        $simulation = data_get($payload, 'simulation');

        if (! is_array($simulation)) {
            return [];
        }

        return [
            'simulation_product' => $this->stringOrNull(data_get($simulation, 'product'), 30),
            'simulation_bank' => $this->stringOrNull(data_get($simulation, 'bank'), 50),
            'simulation_liquid_value' => $this->decimalOrNull(data_get($simulation, 'liquid_value')),
            'simulation_number_of_payments' => $this->integerOrNull(data_get($simulation, 'number_of_payments')),
            'simulation_installment_value' => $this->decimalOrNull(data_get($simulation, 'installment_value')),
            'simulation_monthly_fee' => $this->decimalOrNull(data_get($simulation, 'monthly_fee'), 4),
            'simulation_table_name' => $this->stringOrNull(data_get($simulation, 'table_name'), 255),
            'simulation_table_id' => $this->stringOrNull(data_get($simulation, 'table_id'), 100),
            'simulation_best_liquid_value' => $this->decimalOrNull(data_get($simulation, 'best_liquid_value')),
            'simulation_best_table_id' => $this->stringOrNull(data_get($simulation, 'best_table_id'), 100),
            'simulation_table_details' => is_array(data_get($simulation, 'table_details')) ? data_get($simulation, 'table_details') : null,
            'simulation_received_at' => $now,
        ];
    }

    private function proposalCreatedAttributes(array $payload, Carbon $now): array
    {
        $proposal = data_get($payload, 'proposal');

        if (! is_array($proposal)) {
            return [];
        }

        return [
            'proposal_id' => $this->stringOrNull(data_get($proposal, 'proposal_id'), 100),
            'proposal_number' => $this->stringOrNull(data_get($proposal, 'proposal_number'), 100),
            'proposal_status' => $this->stringOrNull(data_get($proposal, 'proposal_status'), 100),
            'proposal_bank' => $this->stringOrNull(data_get($proposal, 'bank'), 50),
            'proposal_product' => $this->stringOrNull(data_get($proposal, 'product'), 30),
            'proposal_liquid_value' => $this->decimalOrNull(data_get($proposal, 'liquid_value')),
            'proposal_gross_value' => $this->decimalOrNull(data_get($proposal, 'gross_value')),
            'proposal_number_of_payments' => $this->integerOrNull(data_get($proposal, 'number_of_payments')),
            'proposal_installment_value' => $this->decimalOrNull(data_get($proposal, 'installment_value')),
            'proposal_table_name' => $this->stringOrNull(data_get($proposal, 'table_name'), 255),
            'proposal_table_id' => $this->stringOrNull(data_get($proposal, 'table_id'), 100),
            'proposal_formalization_link' => $this->stringOrNull(data_get($proposal, 'formalization_link')),
            'proposal_created_at' => $now,
        ];
    }

    private function proposalStatusAttributes(array $payload, Carbon $now): array
    {
        $status = data_get($payload, 'proposal_status');

        if (! is_array($status)) {
            return [];
        }

        return [
            'proposal_id' => $this->stringOrNull(data_get($status, 'proposal_id'), 100),
            'proposal_number' => $this->stringOrNull(data_get($status, 'proposal_number'), 100),
            'proposal_status' => $this->stringOrNull(data_get($status, 'proposal_status'), 100),
            'previous_proposal_status' => $this->stringOrNull(data_get($status, 'previous_proposal_status'), 100),
            'proposal_status_updated_at' => $now,
        ];
    }

    private function stringOrNull(mixed $value, ?int $maxLength = null): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $maxLength === null ? $value : mb_substr($value, 0, $maxLength);
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function decimalOrNull(mixed $value, int $scale = 2): ?string
    {
        return is_numeric($value) ? number_format((float) $value, $scale, '.', '') : null;
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
