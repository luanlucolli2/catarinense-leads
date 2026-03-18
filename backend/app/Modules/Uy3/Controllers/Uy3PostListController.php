<?php

namespace App\Modules\Uy3\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Uy3WebhookPost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class Uy3PostListController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'q' => ['nullable', 'string', 'min:3', 'max:120'],
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

        [$from, $to] = $this->resolveDateRange($validated, $period);

        $query = $this->buildBaseQuery($from, $to, $sort, $direction);

        $search = trim((string) ($validated['q'] ?? ''));
        $searchMode = 'none';
        if ($search !== '') {
            $searchMode = $this->applySearchFilter($query, $search);
        }

        try {
            $items = $this->paginateAndTransform($query, $perPage);
        } catch (QueryException $e) {
            // Compatibilidade: ambiente sem migration de FULLTEXT/coluna ainda.
            if ($search !== '' && $searchMode === 'fulltext' && $this->shouldFallbackSearch($e)) {
                $fallbackQuery = $this->buildBaseQuery($from, $to, $sort, $direction);
                $this->applySearchFilter($fallbackQuery, $search, forceFallback: true);
                $items = $this->paginateAndTransform($fallbackQuery, $perPage);
            } else {
                throw $e;
            }
        }

        return response()->json($items);
    }

    private function decodeJsonPayload(mixed $payload): mixed
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload)) {
            return $payload;
        }

        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['raw' => $payload];
        }
    }

    private function resolveDateRange(array $validated, string $period): array
    {
        $from = isset($validated['from']) ? $this->parseDateBoundary((string) $validated['from'], false) : null;
        $to = isset($validated['to']) ? $this->parseDateBoundary((string) $validated['to'], true) : null;

        if ($from === null && $to === null && $period !== 'all') {
            $now = now();
            $from = match ($period) {
                '24h' => $now->copy()->subDay(),
                '7d' => $now->copy()->subDays(7),
                '30d' => $now->copy()->subDays(30),
                '90d' => $now->copy()->subDays(90),
                default => $now->copy()->subDays(30),
            };
        }

        return [$from, $to];
    }

    private function buildBaseQuery(?Carbon $from, ?Carbon $to, string $sort, string $direction): Builder
    {
        $query = Uy3WebhookPost::query();

        if ($from !== null) {
            $query->where('received_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('received_at', '<=', $to);
        }

        $query->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }

        return $query;
    }

    private function paginateAndTransform(Builder $query, int $perPage)
    {
        return $query
            ->paginate($perPage)
            ->through(function (Uy3WebhookPost $post): array {
                return [
                    'id' => (string) $post->id,
                    'received_at' => $post->received_at?->toIso8601String(),
                    'dados' => $this->decodeJsonPayload($post->payload),
                ];
            });
    }

    private function parseDateBoundary(string $value, bool $isEnd): Carbon
    {
        $date = Carbon::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $isEnd ? $date->endOfDay() : $date->startOfDay();
        }

        return $date;
    }

    private function toBooleanFullTextQuery(string $search): ?string
    {
        $tokens = $this->extractSearchTokens($search);

        if (empty($tokens)) {
            return null;
        }

        $tokens = array_slice($tokens, 0, 6);

        return implode(' ', array_map(
            static fn (string $token): string => '+' . $token . '*',
            $tokens
        ));
    }

    private function extractSearchTokens(string $input): array
    {
        $normalized = $this->normalizeSearchText($input);
        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(
            array_unique(explode(' ', $normalized)),
            fn (string $token): bool => mb_strlen($token, 'UTF-8') >= 3
        ));
    }

    private function applySearchFilter(Builder $query, string $search, bool $forceFallback = false): string
    {
        if (! $forceFallback && Schema::hasColumn('uy3_webhook_posts', 'search_text')) {
            $booleanQuery = $this->toBooleanFullTextQuery($search);
            if ($booleanQuery !== null) {
                $query->whereRaw("MATCH(search_text) AGAINST (? IN BOOLEAN MODE)", [$booleanQuery]);
                return 'fulltext';
            }
        }

        $tokens = array_slice($this->extractSearchTokens($search), 0, 3);
        if (empty($tokens)) {
            $query->whereRaw('1 = 0');
            return 'none';
        }

        // Fallback leve para ambientes sem FULLTEXT (migração ainda não aplicada).
        foreach ($tokens as $token) {
            $query->where('payload', 'like', '%' . $token . '%');
        }

        return 'fallback_like';
    }

    private function shouldFallbackSearch(QueryException $e): bool
    {
        $message = mb_strtolower($e->getMessage(), 'UTF-8');

        return str_contains($message, 'unknown column')
            || str_contains($message, 'fulltext')
            || str_contains($message, 'match(');
    }

    private function normalizeSearchText(string $input): string
    {
        $normalized = mb_strtolower($input, 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
