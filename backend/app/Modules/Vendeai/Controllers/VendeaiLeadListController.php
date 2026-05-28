<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Models\VendeaiLead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class VendeaiLeadListController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'sort' => ['nullable', Rule::in(['last_received_at', 'id'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = (string) ($validated['sort'] ?? 'last_received_at');
        $direction = strtolower((string) ($validated['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return response()->json(
            VendeaiLead::query()
                ->orderBy($sort, $direction)
                ->paginate($perPage)
        );
    }
}
