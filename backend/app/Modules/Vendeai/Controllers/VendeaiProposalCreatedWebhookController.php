<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class VendeaiProposalCreatedWebhookController extends Controller
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

        if (trim($rawPayload) === '') {
            return response()->json([
                'ok' => true,
            ]);
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json([
                'ok' => true,
            ]);
        }

        if (! is_array($payload) || ($payload['event'] ?? null) !== 'proposal_created') {
            return response()->json([
                'ok' => true,
            ]);
        }

        $this->incrementCounter('proposal_created');

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
}
