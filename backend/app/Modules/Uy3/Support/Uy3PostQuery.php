<?php

declare(strict_types=1);

namespace App\Modules\Uy3\Support;

use App\Models\Uy3WebhookPost;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use JsonException;

final class Uy3PostQuery
{
    private static ?bool $hasTypeWebhookColumn = null;

    public static function resolveDateRange(array $validated, string $period): array
    {
        $from = isset($validated['from']) ? self::parseDateBoundary((string) $validated['from'], false) : null;
        $to = isset($validated['to']) ? self::parseDateBoundary((string) $validated['to'], true) : null;

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

    public static function buildBaseQuery(?Carbon $from, ?Carbon $to, string $sort, string $direction): EloquentBuilder
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

    public static function applyTypeWebhookFilter(EloquentBuilder|QueryBuilder $query, string|array $typeWebhook): void
    {
        $types = array_values(array_filter((array) $typeWebhook, static fn (mixed $value): bool => is_string($value) && $value !== ''));
        if ($types === []) {
            return;
        }

        if (self::hasTypeWebhookColumn()) {
            count($types) === 1
                ? $query->where('type_webhook', $types[0])
                : $query->whereIn('type_webhook', $types);
            return;
        }

        count($types) === 1
            ? $query->where('payload->typeWebook', $types[0])
            : $query->whereIn('payload->typeWebook', $types);
    }

    public static function decodeJsonPayload(mixed $payload): mixed
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

    private static function parseDateBoundary(string $value, bool $isEnd): Carbon
    {
        $date = Carbon::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $isEnd ? $date->endOfDay() : $date->startOfDay();
        }

        return $date;
    }

    private static function hasTypeWebhookColumn(): bool
    {
        if (self::$hasTypeWebhookColumn !== null) {
            return self::$hasTypeWebhookColumn;
        }

        self::$hasTypeWebhookColumn = Schema::hasColumn('uy3_webhook_posts', 'type_webhook');

        return self::$hasTypeWebhookColumn;
    }
}
