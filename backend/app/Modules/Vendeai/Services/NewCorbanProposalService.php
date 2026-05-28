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
        $sentAt = now();

        if ($baseUrl === '') {
            $webhook->update([
                'newcorban_request_payload' => $requestPayload,
                'newcorban_error' => 'NEWCORBAN_URL not configured.',
            ]);

            return;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(max(1, (int) config('newcorban.timeout', 15)))
                ->post($baseUrl . '/api/propostas/', $requestPayload);

            $responseBody = $response->json();

            if (! is_array($responseBody)) {
                $responseBody = ['raw' => $response->body()];
            }

            $webhook->update([
                'newcorban_request_payload' => $requestPayload,
                'newcorban_response_status' => $response->status(),
                'newcorban_response_body' => $responseBody,
                'newcorban_proposta_id' => $this->stringOrNull($responseBody['proposta_id'] ?? null, 80),
                'newcorban_cliente_id' => $this->stringOrNull($responseBody['cliente_id'] ?? null, 80),
                'newcorban_sent_at' => $sentAt,
                'newcorban_error' => $response->successful() ? null : 'HTTP ' . $response->status(),
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

        return [
            'auth' => [
                'username' => (string) config('newcorban.username'),
                'password' => (string) config('newcorban.password'),
                'empresa' => (string) config('newcorban.empresa'),
            ],
            'requestType' => 'createProposta',
            'content' => [
                'cliente' => [
                    'pessoais' => [
                        'cpf' => $cpf,
                        'nome' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.name')),
                        'nascimento' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.birth_date')),
                        'sexo' => null,
                        'estado_civil' => 'SOLTEIRO',
                        'nacionalidade' => 'BRASILEIRO',
                        'mae' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.mother_name')),
                        'pai' => 'nao informado',
                        'renda' => 1412,
                        'email' => $this->stringOrNull(data_get($payload, 'chat_summary.details.contact.email')),
                        'falecido' => false,
                        'nao_perturbe' => false,
                        'analfabeto' => false,
                    ],
                    'documentos' => $cpf === null ? null : [
                        $cpf => [
                            'numero' => $cpf,
                            'tipo' => 'CPF',
                            'data_emissao' => null,
                            'uf' => null,
                        ],
                    ],
                    'enderecos' => null,
                    'telefones' => $phone['numero'] === null ? null : [
                        $phone['numero'] => [
                            'ddd' => $phone['ddd'],
                            'numero' => $phone['numero'],
                        ],
                    ],
                ],
                'proposta' => [
                    'documento_id' => $cpf,
                    'endereco_id' => null,
                    'telefone_id' => $phone['numero'],
                    'banco_id' => null,
                    'convenio_id' => null,
                    'proposta_id_banco' => $this->stringOrNull(data_get($payload, 'proposal.proposal_number')),
                    'produto_id' => null,
                    'status' => 'DIGITADA',
                    'tipo_cadastro' => 'API',
                    'tipo_liberacao' => null,
                    'banco_averbacao' => null,
                    'conta' => null,
                    'conta_digito' => null,
                    'agencia' => null,
                    'promotora_id' => null,
                    'link_formalizacao' => $this->stringOrNull(data_get($payload, 'proposal.formalization_link')),
                    'vendedor' => null,
                    'franquia_id' => null,
                    'vendedor_participante' => null,
                    'origem_id' => null,
                    'proposta_id' => false,
                    'login_digitacao' => null,
                    'valor_parcela' => $this->numberOrNull(data_get($payload, 'proposal.installment_value')),
                    'valor_financiado' => $this->numberOrNull(data_get($payload, 'proposal.gross_value')),
                    'valor_liberado' => $this->numberOrNull(data_get($payload, 'proposal.liquid_value')),
                    'prazo' => $this->integerOrNull(data_get($payload, 'proposal.number_of_payments')),
                    'taxa' => null,
                    'tabela_id' => $this->stringOrNull(data_get($payload, 'proposal.table_id')),
                ],
            ],
        ];
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

    private function onlyDigits(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits === '' ? null : $digits;
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
