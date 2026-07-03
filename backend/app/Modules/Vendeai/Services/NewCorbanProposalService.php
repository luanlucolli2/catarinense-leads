<?php

namespace App\Modules\Vendeai\Services;

use App\Modules\Vendeai\Models\VendeaiProposalCreatedWebhook;
use Illuminate\Support\Facades\Http;
use Throwable;

class NewCorbanProposalService
{
    public function sendProposalCreated(VendeaiProposalCreatedWebhook $webhook, array $payload): void
    {
        $requestPayload = $this->buildPayload($payload);
        $baseUrl = rtrim((string) config('newcorban.base_url'), '/');
        $apiToken = trim((string) config('newcorban.api_token'));
        $sentAt = now();

        if ($baseUrl === '' || $apiToken === '') {
            $webhook->update([
                'newcorban_request_payload' => $requestPayload,
                'newcorban_error' => 'NEWCORBAN_BASE_URL or NEWCORBAN_API_TOKEN not configured.',
            ]);

            return;
        }

        try {
            $response = Http::acceptJson()
                ->withToken($apiToken)
                ->asJson()
                ->timeout(max(1, (int) config('newcorban.timeout', 15)))
                ->post($baseUrl . '/proposals', $requestPayload);

            $responseBody = $response->json();

            if (! is_array($responseBody)) {
                $responseBody = ['raw' => $response->body()];
            }

            $webhook->update([
                'newcorban_request_payload' => $requestPayload,
                'newcorban_response_status' => $response->status(),
                'newcorban_response_body' => $responseBody,
                'newcorban_proposta_id' => $this->stringOrNull(data_get($responseBody, 'data.id') ?? ($responseBody['proposta_id'] ?? null), 80),
                'newcorban_cliente_id' => $this->stringOrNull(
                    data_get($responseBody, 'data.customer_id')
                    ?? data_get($responseBody, 'data.cliente_id')
                    ?? ($responseBody['cliente_id'] ?? null),
                    80
                ),
                'newcorban_sent_at' => $sentAt,
                'newcorban_error' => $this->responseError($response->status(), $responseBody),
            ]);
        } catch (Throwable $e) {
            $webhook->update([
                'newcorban_request_payload' => $requestPayload,
                'newcorban_sent_at' => $sentAt,
                'newcorban_error' => $this->stringOrNull($e->getMessage(), 1000),
            ]);
        }
    }

    public function buildPayload(array $payload): array
    {
        $cpf = $this->onlyDigits(data_get($payload, 'chat_summary.details.contact.cpf'));
        $phone = $this->phoneParts(data_get($payload, 'chat_summary.details.contact.phone'));

        $requestPayload = $this->filterNulls([
            'proposal' => [
                'bank_id' => $this->newCorbanBankId(data_get($payload, 'proposal.bank')),
                'covenant_id' => $this->newCorbanConvenioId(data_get($payload, 'proposal.product')),
                'product_id' => $this->newCorbanProductId(data_get($payload, 'proposal.product')),
                'promoter_id' => $this->newCorbanPromotoraId(data_get($payload, 'proposal.bank')),
                'origin_id' => $this->defaultConfigValue('origin_id'),
                'table_code' => $this->newCorbanTableCode(
                    data_get($payload, 'proposal.bank'),
                    data_get($payload, 'proposal.table_id')
                ),
                'term' => $this->integerOrNull(data_get($payload, 'proposal.number_of_payments')),
                'rate' => null,
                'financed_amount' => $this->numberOrNull(data_get($payload, 'proposal.gross_value')),
                'installment_amount' => $this->numberOrNull(data_get($payload, 'proposal.installment_value')),
                'released_amount' => $this->numberOrNull(data_get($payload, 'proposal.liquid_value')),
                'typing_login' => $this->newCorbanLoginDigitacao(data_get($payload, 'proposal.bank')),
            ],
            'assignment' => [
                'seller_id' => $this->defaultConfigValue('seller_id'),
                'co_seller_id' => $this->defaultConfigValue('co_seller_id'),
                'team_id' => $this->defaultConfigValue('team_id'),
                'franchise_id' => $this->defaultConfigValue('franchise_id'),
            ],
            'bank_reference' => [
                'proposal_number' => $this->stringOrNull(data_get($payload, 'proposal.proposal_number')),
                'api_reference' => $this->stringOrNull(data_get($payload, 'proposal.proposal_id')),
                'formalization_link' => $this->stringOrNull(data_get($payload, 'proposal.formalization_link')),
            ],
            'customer' => $this->filterNulls([
                'cpf' => $cpf,
                'name' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.name')),
                'birth_date' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.birth_date')),
                'gender' => null,
                'marital_status' => 'SOLTEIRO',
                'nationality' => 'BRASILEIRO',
                'mother_name' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.mother_name')),
                'father_name' => 'nao informado',
                'income' => 1412,
                'email' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.email')),
                'deceased' => false,
                'do_not_disturb' => false,
                'illiterate' => false,
            ]),
            'phone' => $phone['numero'] === null ? null : [
                'area_code' => $phone['ddd'],
                'number' => $phone['numero'],
                'type' => 'CELULAR',
            ],
        ]);

        $requestPayload['proposal']['status_id'] = null;

        return $requestPayload;
    }

    private function phoneParts(mixed $value): array
    {
        $digits = $this->onlyDigits($value);

        if ($digits !== null && strlen($digits) > 11 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        if ($digits === null || strlen($digits) < 3) {
            return ['ddd' => null, 'numero' => null];
        }

        return [
            'ddd' => substr($digits, 0, 2),
            'numero' => substr($digits, 2),
        ];
    }

    private function responseError(int $status, array $body): ?string
    {
        if ($status === 201 && ($body['success'] ?? false) === true) {
            return null;
        }

        if (($body['success'] ?? null) === false || ($body['error'] ?? false) === true) {
            return $this->firstResponseMessage($body) ?? 'New Corban returned error.';
        }

        return $this->firstResponseMessage($body) ?? ('HTTP ' . $status);
    }

    private function newCorbanProductId(mixed $value): ?string
    {
        return $this->configValueForProduct($value, 'product_id');
    }

    private function newCorbanBankId(mixed $value): ?string
    {
        return $this->configValueForBank($value, 'bank_id');
    }

    private function newCorbanPromotoraId(mixed $value): ?string
    {
        return $this->configValueForBank($value, 'promoter_id');
    }

    private function newCorbanLoginDigitacao(mixed $value): ?string
    {
        return $this->configValueForBank($value, 'typing_login');
    }

    private function newCorbanConvenioId(mixed $value): ?string
    {
        return $this->configValueForProduct($value, 'covenant_id');
    }

    private function newCorbanTableCode(mixed $bank, mixed $tableId): ?string
    {
        $bankConfig = $this->bankConfig($bank);

        if (($bankConfig['omit_table_code'] ?? false) === true) {
            return null;
        }

        return $this->stringOrNull($tableId);
    }

    private function onlyDigits(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits === '' ? null : $digits;
    }

    private function bankKey(mixed $value): string
    {
        $normalized = mb_strtolower((string) $this->stringOrNull($value));
        $collapsed = str_replace([' ', '_', '-'], '', $normalized);

        return match (true) {
            $normalized === 'presença' || str_contains($collapsed, 'presenca') => 'presenca',
            str_contains($collapsed, 'mercantil') => 'mercantil',
            str_contains($collapsed, 'novosaque') => 'novo_saque',
            str_contains($collapsed, 'soma') => 'soma',
            str_starts_with($collapsed, 'facta') => 'facta',
            str_starts_with($collapsed, 'pan') => 'pan',
            str_starts_with($collapsed, 'c6') => 'c6',
            default => $normalized,
        };
    }

    private function productKey(mixed $value): ?string
    {
        $normalized = mb_strtolower((string) $this->stringOrNull($value));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function bankConfig(mixed $value): array
    {
        $config = config('newcorban.banks.' . $this->bankKey($value), []);

        return is_array($config) ? $config : [];
    }

    private function configValueForBank(mixed $value, string $field): ?string
    {
        return $this->stringOrNull($this->bankConfig($value)[$field] ?? null);
    }

    private function configValueForProduct(mixed $value, string $field): ?string
    {
        $productKey = $this->productKey($value);

        if ($productKey === null) {
            return null;
        }

        $config = config('newcorban.products.' . $productKey, []);

        if (! is_array($config)) {
            return null;
        }

        return $this->stringOrNull($config[$field] ?? null);
    }

    private function defaultConfigValue(string $field): ?string
    {
        return $this->stringOrNull(config('newcorban.defaults.' . $field));
    }

    private function firstResponseMessage(array $body): ?string
    {
        $message = $this->stringOrNull($body['message'] ?? null, 1000)
            ?? $this->stringOrNull($body['mensagem'] ?? null, 1000);

        if ($message !== null) {
            return $message;
        }

        $messages = $this->flattenStringValues($body['errors'] ?? null);

        if ($messages === []) {
            return null;
        }

        return $this->stringOrNull(implode(' | ', array_slice($messages, 0, 3)), 1000);
    }

    /**
     * @return list<string>
     */
    private function flattenStringValues(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            $string = $this->stringOrNull($value);

            return $string === null ? [] : [$string];
        }

        $values = [];

        foreach ($value as $item) {
            foreach ($this->flattenStringValues($item) as $stringValue) {
                $values[] = $stringValue;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterNulls(array $payload): array
    {
        $filtered = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $value = $this->filterNulls($value);
            }

            if ($value === null || $value === []) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
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

    private function numberOrNull(mixed $value): int|float|null
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
