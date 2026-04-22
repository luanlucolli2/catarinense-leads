<?php

namespace App\Modules\Uy3\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Uy3\Support\Uy3PostQuery;
use App\Models\Uy3WebhookPost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class Uy3PostListController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'period' => ['nullable', Rule::in(['all', '24h', '7d', '30d', '90d'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['received_at', 'id'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = (string) ($validated['sort'] ?? 'received_at');
        $direction = strtolower((string) ($validated['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $period = (string) ($validated['period'] ?? '30d');

        [$from, $to] = Uy3PostQuery::resolveDateRange($validated, $period);

        $query = Uy3PostQuery::buildBaseQuery($from, $to, $sort, $direction);

        $items = $this->paginateAndTransform($query, $perPage);

        return response()->json($items);
    }

    private function paginateAndTransform(Builder $query, int $perPage)
    {
        return $query
            ->paginate($perPage)
            ->through(function (Uy3WebhookPost $post): array {
                return [
                    'id' => (string) $post->id,
                    'received_at' => $post->received_at?->toIso8601String(),
                    'dados' => Uy3PostQuery::decodeJsonPayload($post->payload),
                ];
            });
    }
}
