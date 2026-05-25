<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Models\VendeaiProposalCreatedWebhook;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class VendeaiWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        $configuredToken = (string) config('vendeai.webhook_token');

        if ($configuredToken === '' || ! hash_equals($configuredToken, $token)) {
            return response()->json([
                'error' => 'not_found',
            ], 404);
        }

        $rawPayload = (string) $request->getContent();
        $payload = null;
        $event = 'unknown';

        if (trim($rawPayload) !== '') {
            try {
                $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
                $event = $this->eventName($payload);
            } catch (JsonException) {
                $payload = null;
            }
        }

        $this->incrementCounter($event);

        if ($event !== 'proposal_created' || ! is_array($payload)) {
            return response()->json([
                'ok' => true,
            ]);
        }

        VendeaiProposalCreatedWebhook::create($this->proposalCreatedAttributes($payload));

        return response()->json([
            'ok' => true,
        ]);
    }

    private function incrementCounter(string $event): void
    {
        $now = now();

        $updated = DB::table('vendeai_webhook_counters')
            ->where('event', $event)
            ->increment('received_count', 1, [
                'last_received_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated > 0) {
            return;
        }

        try {
            DB::table('vendeai_webhook_counters')->insert([
                'event' => $event,
                'received_count' => 1,
                'last_received_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException) {
            DB::table('vendeai_webhook_counters')
                ->where('event', $event)
                ->increment('received_count', 1, [
                    'last_received_at' => $now,
                    'updated_at' => $now,
                ]);
        }
    }

    private function proposalCreatedAttributes(array $payload): array
    {
        return [
            'received_at' => now(),
            'account_id' => $this->stringOrNull(data_get($payload, 'chat_summary.account_id'), 80),
            'chat_id' => $this->stringOrNull(data_get($payload, 'chat_summary.chat_id'), 80),
            'chat_product' => $this->stringOrNull(data_get($payload, 'chat_summary.product'), 40),
            'chat_stage' => $this->stringOrNull(data_get($payload, 'chat_summary.stage'), 80),
            'contact_name' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.name'), 180),
            'contact_phone' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.phone'), 40),
            'contact_email' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.email'), 180),
            'contact_cpf' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.cpf'), 20),
            'contact_birth_date' => $this->dateOrNull(data_get($payload, 'chat_summary.details.contact.birth_date')),
            'contact_mother_name' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.mother_name'), 180),
            'session_campaign' => $this->stringOrNull(data_get($payload, 'chat_summary.details.session.campaign'), 180),
            'session_inbox_phone_number' => $this->stringOrNull(data_get($payload, 'chat_summary.details.session.inbox_phone_number'), 40),
            'session_product_being_processed' => $this->stringOrNull(data_get($payload, 'chat_summary.details.session.product_being_processed'), 40),
            'tags' => is_array(data_get($payload, 'chat_summary.tags')) ? data_get($payload, 'chat_summary.tags') : null,
            'proposal_id' => $this->stringOrNull(data_get($payload, 'proposal.proposal_id'), 120),
            'proposal_number' => $this->stringOrNull(data_get($payload, 'proposal.proposal_number'), 80),
            'proposal_status' => $this->stringOrNull(data_get($payload, 'proposal.proposal_status'), 80),
            'bank' => $this->stringOrNull(data_get($payload, 'proposal.bank'), 40),
            'proposal_product' => $this->stringOrNull(data_get($payload, 'proposal.product'), 40),
            'liquid_value' => $this->decimalOrNull(data_get($payload, 'proposal.liquid_value')),
            'gross_value' => $this->decimalOrNull(data_get($payload, 'proposal.gross_value')),
            'number_of_payments' => $this->integerOrNull(data_get($payload, 'proposal.number_of_payments')),
            'installment_value' => $this->decimalOrNull(data_get($payload, 'proposal.installment_value')),
            'table_name' => $this->stringOrNull(data_get($payload, 'proposal.table_name'), 180),
            'table_id' => $this->stringOrNull(data_get($payload, 'proposal.table_id'), 120),
            'formalization_link' => $this->stringOrNull(data_get($payload, 'proposal.formalization_link')),
            'raw_payload' => $payload,
        ];
    }

    private function eventName(mixed $payload): string
    {
        if (! is_array($payload)) {
            return 'unknown';
        }

        return $this->stringOrNull($payload['event'] ?? null, 80) ?? 'unknown';
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

    private function decimalOrNull(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
