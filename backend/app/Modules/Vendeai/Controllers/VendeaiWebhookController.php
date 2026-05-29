<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Models\VendeaiProposalCreatedWebhook;
use App\Modules\Vendeai\Services\VendeaiLeadUpsertService;
use App\Modules\Vendeai\Services\NewCorbanProposalService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class VendeaiWebhookController extends Controller
{
    public function __construct(
        private readonly NewCorbanProposalService $newCorbanProposalService,
        private readonly VendeaiLeadUpsertService $vendeaiLeadUpsertService,
    )
    {
    }

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

        if (! is_array($payload)) {
            return response()->json([
                'ok' => true,
            ]);
        }

        $lead = $this->vendeaiLeadUpsertService->upsert($payload, $event);

        if ($event !== 'proposal_created') {
            return response()->json([
                'ok' => true,
            ]);
        }

        $webhook = VendeaiProposalCreatedWebhook::create(
            $this->proposalCreatedAttributes($payload, $lead?->id)
        );

        $this->newCorbanProposalService->sendProposalCreated($webhook, $payload);

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

    private function proposalCreatedAttributes(array $payload, ?int $leadId): array
    {
        return [
            'vendeai_lead_id' => $leadId,
            'received_at' => now(),
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

}
