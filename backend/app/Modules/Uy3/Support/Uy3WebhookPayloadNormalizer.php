<?php

declare(strict_types=1);

namespace App\Modules\Uy3\Support;

use App\Support\Cpf;
use Illuminate\Validation\ValidationException;

final class Uy3WebhookPayloadNormalizer
{
    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    public static function normalize(mixed $payload): array
    {
        if (! is_array($payload) || array_is_list($payload)) {
            throw ValidationException::withMessages([
                'payload' => ['Request body must be a JSON object.'],
            ]);
        }

        $cpf = Cpf::normalize(self::stringOrNull($payload['cpf'] ?? null));
        if ($cpf === null || ! Cpf::isValid($cpf)) {
            throw ValidationException::withMessages([
                'cpf' => ['CPF invalido ou ausente.'],
            ]);
        }

        $payload['cpf'] = $cpf;

        $type = self::stringOrNull($payload['typeWebhook'] ?? ($payload['typeWebook'] ?? null));

        if ($type !== null) {
            $payload['typeWebhook'] = $type;
        } else {
            unset($payload['typeWebhook']);
        }

        unset($payload['typeWebook']);

        return $payload;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
