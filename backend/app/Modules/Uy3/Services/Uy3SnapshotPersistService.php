<?php

declare(strict_types=1);

namespace App\Modules\Uy3\Services;

use App\Support\Cpf;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Uy3SnapshotPersistService
{
    public function persist(array $payload, DateTimeInterface|string|null $snapshotUpdatedAt = null): void
    {
        $cpf = Cpf::normalize($this->stringOrNull($payload['cpf'] ?? null));

        if ($cpf === null || ! Cpf::isValid($cpf)) {
            throw new InvalidArgumentException('CPF invalido para persistencia UY3.');
        }

        $row = [
            'cpf' => $cpf,
            'type_webhook' => $this->stringOrNull($payload['typeWebhook'] ?? null),
            'status' => $this->stringOrNull($payload['status'] ?? null),
            'data_admissao' => $this->dateOrNull($payload['dataAdmissao'] ?? null),
            'valor_liberado' => $this->decimal($payload['valorLiberado'] ?? null, 2),
            'numero_parcelas' => $this->intOrNull($payload['numeroParcelas'] ?? null, 0, 65535),
            'codigo_requisicao' => $this->stringOrNull($payload['codigoRequisicao'] ?? null),
            'margem_disponivel' => $this->decimal($payload['margemDisponivel'] ?? null, 2),
            'elegivel_emprestimo' => $this->boolOrNull($payload['elegivelEmprestimo'] ?? null),
            'numero_inscricao_empregador' => $this->stringOrNull($payload['numeroInscricaoEmpregador'] ?? null),
            'pessoa_exposta_politicamente_codigo' => $this->intOrNull(data_get($payload, 'pessoaExpostaPoliticamente.Codigo')),
            'data_hora_validade_solicitacao' => $this->dateTimeOrNull($payload['dataHoraValidadeSolicitacao'] ?? null),
            'is_mei' => $this->boolOrNull($payload['is_mei'] ?? null),
            'active_fgts_debts' => $this->jsonOrNull($payload['active_fgts_debts'] ?? null),
            'all_branch_employees' => $this->jsonOrNull($payload['all_branch_employees'] ?? null),
            'is_judicial_recovery' => $this->boolOrNull($payload['is_judicial_recovery'] ?? null),
            'updated_at' => $this->snapshotTimestamp($snapshotUpdatedAt),
        ];

        DB::table('uy3_snapshots')->upsert(
            [$row],
            ['cpf'],
            [
                'type_webhook',
                'status',
                'data_admissao',
                'valor_liberado',
                'numero_parcelas',
                'codigo_requisicao',
                'margem_disponivel',
                'elegivel_emprestimo',
                'numero_inscricao_empregador',
                'pessoa_exposta_politicamente_codigo',
                'data_hora_validade_solicitacao',
                'is_mei',
                'active_fgts_debts',
                'all_branch_employees',
                'is_judicial_recovery',
                'updated_at',
            ],
        );

        $this->upsertLeadBasics(
            $cpf,
            $payload['nomeTrabalhador'] ?? null,
            $this->dateOrNull($payload['dataNascimento'] ?? null),
        );
    }

    private function upsertLeadBasics(string $cpf, mixed $nome, ?string $dataNascimento): void
    {
        $now = now()->format('Y-m-d H:i:s');

        DB::statement(
            <<<'SQL'
            INSERT INTO leads (cpf, nome, data_nascimento, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                nome = IF(leads.nome IS NULL, VALUES(nome), leads.nome),
                data_nascimento = IF(leads.data_nascimento IS NULL, VALUES(data_nascimento), leads.data_nascimento),
                updated_at = IF(
                    (leads.nome IS NULL AND VALUES(nome) IS NOT NULL)
                    OR (leads.data_nascimento IS NULL AND VALUES(data_nascimento) IS NOT NULL),
                    VALUES(updated_at),
                    leads.updated_at
                )
            SQL,
            [
                $cpf,
                $this->stringOrNull($nome),
                $dataNascimento,
                $now,
                $now,
            ],
        );
    }

    private function snapshotTimestamp(DateTimeInterface|string|null $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d H:i:s');
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->format('Y-m-d H:i:s');
            } catch (\Throwable) {
            }
        }

        return now()->format('Y-m-d H:i:s');
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function decimal(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, $scale, '.', '');
    }

    private function intOrNull(mixed $value, ?int $min = null, ?int $max = null): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $value = (int) $value;

        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            return null;
        }

        return $value;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return match ((int) $value) {
                1 => true,
                0 => false,
                default => null,
            };
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'sim' => true,
            '0', 'false', 'no', 'nao', 'não' => false,
            default => null,
        };
    }

    private function jsonOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
