<?php

namespace App\Modules\Mercantil\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Cpf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class MercantilSnapshotWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $rawPayload = (string) $request->getContent();

        if (trim($rawPayload) === '') {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Empty JSON payload.',
            ], 422);
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Request body must be valid JSON.',
            ], 422);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Request body must be a JSON object.',
            ], 422);
        }

        $validator = Validator::make($payload, [
            'cpf' => ['required', 'string'],
            'nome' => ['required', 'string', 'max:150'],
            'data_nascimento' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', 'string', 'max:64'],
            'mensagem_erro' => ['present', 'nullable', 'string'],
            'data_hora' => ['required', 'string'],
            'valor_financiado' => ['present', 'nullable', 'numeric'],
            'valor_iof' => ['present', 'nullable', 'numeric'],
            'data_primeiro_vencimento' => ['present', 'nullable', 'date_format:Y-m-d'],
            'valor_emprestimo' => ['present', 'nullable', 'numeric'],
            'quantidade_parcelas' => ['present', 'nullable', 'integer', 'min:0', 'max:65535'],
            'valor_liberado' => ['present', 'nullable', 'numeric'],
            'taxa_juros_mes' => ['present', 'nullable', 'numeric'],
            'valor_parcela' => ['present', 'nullable', 'numeric'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'Invalid webhook payload.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $cpf = Cpf::normalize((string) $payload['cpf']);
        $dataHora = $this->parseDateTime((string) $payload['data_hora']);

        $cpfValid = $cpf !== null && Cpf::isValid($cpf);

        if (! $cpfValid || $dataHora === null) {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'Invalid webhook payload.',
                'errors' => [
                    'cpf' => ! $cpfValid ? ['CPF invalido ou ausente.'] : [],
                    'data_hora' => $dataHora === null ? ['Data/hora invalida ou ausente.'] : [],
                ],
            ], 422);
        }

        $row = [
            'cpf' => $cpf,
            'nome' => trim((string) $payload['nome']),
            'data_nascimento' => $payload['data_nascimento'],
            'status' => mb_strtoupper(trim((string) $payload['status'])),
            'mensagem_erro' => $this->nullableString($payload['mensagem_erro']),
            'data_hora_origem' => $dataHora,
            'valor_financiado' => $this->decimal($payload['valor_financiado'], 2),
            'valor_iof' => $this->decimal($payload['valor_iof'], 2),
            'data_primeiro_vencimento' => $payload['data_primeiro_vencimento'],
            'valor_emprestimo' => $this->decimal($payload['valor_emprestimo'], 2),
            'quantidade_parcelas' => $payload['quantidade_parcelas'] === null ? null : (int) $payload['quantidade_parcelas'],
            'valor_liberado' => $this->decimal($payload['valor_liberado'], 2),
            'taxa_juros_mes' => $this->decimal($payload['taxa_juros_mes'], 4),
            'valor_parcela' => $this->decimal($payload['valor_parcela'], 2),
            'job_id' => null,
            'updated_at' => now(),
        ];

        DB::table('mercantil_snapshots')->upsert(
            [$row],
            ['cpf'],
            [
                'nome',
                'data_nascimento',
                'status',
                'mensagem_erro',
                'data_hora_origem',
                'valor_financiado',
                'valor_iof',
                'data_primeiro_vencimento',
                'valor_emprestimo',
                'quantidade_parcelas',
                'valor_liberado',
                'taxa_juros_mes',
                'valor_parcela',
                'job_id',
                'updated_at',
            ],
        );

        return response()->json([
            'ok' => true,
        ]);
    }

    private function parseDateTime(string $value): ?string
    {
        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function decimal(mixed $value, int $scale): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, $scale, '.', '');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
