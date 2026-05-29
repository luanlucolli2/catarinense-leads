<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Models\VendeaiProposalCreatedWebhook;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class VendeaiProposalCreatedWebhookListController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'sort' => ['nullable', Rule::in(['received_at', 'id'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = (string) ($validated['sort'] ?? 'received_at');
        $direction = strtolower((string) ($validated['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $items = VendeaiProposalCreatedWebhook::query()
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->through(fn (VendeaiProposalCreatedWebhook $webhook): array => $this->sanitize($webhook->toArray()));

        return response()->json($items);
    }

    private function sanitize(array $item): array
    {
        if (isset($item['newcorban_request_payload']['auth']['password'])) {
            $item['newcorban_request_payload']['auth']['password'] = null;
        }

        return $item;
    }
}
