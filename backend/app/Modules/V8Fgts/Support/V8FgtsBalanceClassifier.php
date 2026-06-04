<?php

namespace App\Modules\V8Fgts\Support;

final class V8FgtsBalanceClassifier
{
    public const RETRYABLE = 'retryable';
    public const NAO_ELEGIVEL = 'nao_elegivel';
    public const FALHA = 'falha';

    public static function classifyApiFailure(array $response): array
    {
        $status = isset($response['status']) ? (int) $response['status'] : null;
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $title = self::normalizeText($data['title'] ?? $response['title'] ?? null);
        $detail = self::normalizeText($data['detail'] ?? $response['error'] ?? null);

        return [
            'classification' => self::classify($status, $title, $detail),
            'message' => $detail ?: 'Erro na consulta de saldo FGTS.',
            'title' => $title,
        ];
    }

    public static function classify(?int $status, ?string $title, ?string $detail): string
    {
        $detail = self::normalizeText($detail);
        $title = self::normalizeText($title);

        if (self::isBusinessMessage($detail)) {
            return self::NAO_ELEGIVEL;
        }

        if (
            $status === 400
            && $title === 'BadRequestError'
            && $detail === 'Tente novamente'
        ) {
            return self::RETRYABLE;
        }

        if (
            $status === 400
            && $title === 'AppError'
            && ($detail === 'Ocorreu um erro inesperado' || str_contains($detail, 'Não foi possível consultar o saldo no momento!'))
        ) {
            return self::RETRYABLE;
        }

        return self::FALHA;
    }

    public static function classifyPollingStatus(?string $status, ?string $statusInfo): string
    {
        $normalizedStatus = strtolower(trim((string) $status));
        $statusInfo = self::normalizeText($statusInfo);

        if ($normalizedStatus === 'success') {
            return self::RETRYABLE;
        }

        if (self::isBusinessMessage($statusInfo)) {
            return self::NAO_ELEGIVEL;
        }

        return self::FALHA;
    }

    private static function isBusinessMessage(?string $detail): bool
    {
        $detail = self::normalizeText($detail);
        if ($detail === null) {
            return false;
        }

        return str_contains($detail, 'Trabalhador não possui adesão ao saque aniversário vigente na data corrente')
            || str_contains($detail, 'não possui autorização do Trabalhador')
            || str_contains($detail, 'Existe uma Operação Fiduciária em andamento')
            || str_contains($detail, 'Mudanças cadastrais na conta do FGTS foram realizadas, que impedem a contratação');
    }

    private static function normalizeText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
