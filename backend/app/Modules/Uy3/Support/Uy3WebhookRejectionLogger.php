<?php

declare(strict_types=1);

namespace App\Modules\Uy3\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class Uy3WebhookRejectionLogger
{
    /**
     * @param array<string, mixed> $details
     */
    public static function warning(Request $request, int $status, string $reason, array $details = []): void
    {
        Log::warning('[UY3] Webhook rejeitado.', self::context($request, $status, $reason, $details));
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function critical(Request $request, int $status, string $reason, array $details = []): void
    {
        Log::critical('[UY3] Webhook rejeitado.', self::context($request, $status, $reason, $details));
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private static function context(Request $request, int $status, string $reason, array $details): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => self::headers($request),
            'payload' => (string) $request->getContent(),
            'details' => $details,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $normalizedName = strtolower((string) $name);

            $headers[$name] = self::isSensitiveHeader($normalizedName)
                ? ['[REDACTED]']
                : array_map(static fn (mixed $value): string => (string) $value, $values);
        }

        return $headers;
    }

    private static function isSensitiveHeader(string $name): bool
    {
        return str_contains($name, 'authorization')
            || str_contains($name, 'cookie')
            || str_contains($name, 'secret');
    }
}
