<?php

declare(strict_types=1);

namespace App\Modules\Vendeai\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class VendeaiCsvExport
{
    public const TYPE_LEADS = 'leads';
    public const TYPE_ATTEMPTS = 'newcorban-proposal-attempts';

    public static function filenamePrefix(string $type): string
    {
        return $type === self::TYPE_ATTEMPTS
            ? 'vendeai_newcorban_proposal_attempts'
            : 'vendeai_leads';
    }

    public static function headings(string $type): array
    {
        if ($type === self::TYPE_ATTEMPTS) {
            return [
                'CPF',
                'Nome',
                'Data nascimento',
                'Telefone',
                'Account ID',
                'Chat ID',
                'Proposal ID VendeAI',
                'Banco',
                'Produto',
                'Status proposta',
                'Valor liquido',
                'Valor bruto',
                'Parcelas',
                'Valor parcela',
                'Tabela ID',
                'Banco ID enviado',
                'Produto ID enviado',
                'Convenio ID enviado',
                'Promotora ID enviado',
                'Origem ID enviado',
                'Vendedor enviado',
                'Login digitacao enviado',
                'ID tentativa',
                'Status tentativa',
                'Proposta NewCorban',
                'Cliente NewCorban',
                'HTTP',
                'Erro NewCorban',
                'Recebido em',
                'Enviado NewCorban em',
            ];
        }

        return [
            'CPF',
            'Nome',
            'Data nascimento',
            'Telefone',
            'Email',
            'Account ID',
            'Chat ID',
            'ID lead',
            'Produto conversa',
            'Stage',
            'Tags',
            'Ultimo evento',
            'Produto simulacao',
            'Banco simulacao',
            'Valor liquido simulacao',
            'Quantidade parcelas simulacao',
            'Valor parcela simulacao',
            'Taxa mensal simulacao',
            'Nome tabela simulacao',
            'ID tabela simulacao',
            'Melhor valor liquido simulacao',
            'Melhor tabela simulacao',
            'Detalhes tabela simulacao',
            'Data simulacao',
            'Produto proposta',
            'Banco proposta',
            'Proposal ID',
            'Numero proposta',
            'Status proposta',
            'Status anterior proposta',
            'Valor liquido proposta',
            'Valor bruto proposta',
            'Quantidade parcelas proposta',
            'Valor parcela proposta',
            'Nome tabela proposta',
            'ID tabela proposta',
            'Link formalizacao',
            'Data criacao proposta',
            'Data atualizacao status proposta',
            'Proposta NewCorban',
            'Erro NewCorban',
            'Enviado NewCorban em',
            'Primeiro evento em',
            'Ultimo evento em',
        ];
    }

    public static function writeRows($fh, string $type, array $filters, string $delimiter, string $enclosure, int $flushEvery): int
    {
        $query = $type === self::TYPE_ATTEMPTS
            ? self::buildAttemptsQuery($filters)
            : self::buildLeadsQuery($filters);

        $written = 0;

        foreach ($query->cursor() as $row) {
            fputcsv(
                $fh,
                $type === self::TYPE_ATTEMPTS ? self::mapAttempt($row) : self::mapLead($row),
                $delimiter,
                $enclosure,
                '\\'
            );
            $written++;

            if ($written % $flushEvery === 0) {
                fflush($fh);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        return $written;
    }

    private static function buildLeadsQuery(array $filters): Builder
    {
        [$from, $to] = VendeaiDateRange::fromValidated($filters);
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $latestAttempts = DB::table('vendeai_newcorban_proposal_attempts')
            ->selectRaw('MAX(id) as id, vendeai_lead_id')
            ->whereNotNull('vendeai_lead_id')
            ->groupBy('vendeai_lead_id');

        $query = DB::table('vendeai_leads')
            ->leftJoinSub($latestAttempts, 'latest_attempts', function ($join) {
                $join->on('latest_attempts.vendeai_lead_id', '=', 'vendeai_leads.id');
            })
            ->leftJoin('vendeai_newcorban_proposal_attempts as attempts', 'attempts.id', '=', 'latest_attempts.id')
            ->select([
                'vendeai_leads.id',
                'vendeai_leads.account_id',
                'vendeai_leads.chat_id',
                'vendeai_leads.first_received_at',
                'vendeai_leads.last_received_at',
                'vendeai_leads.last_event',
                'vendeai_leads.chat_product',
                'vendeai_leads.stage',
                'vendeai_leads.tags',
                'vendeai_leads.customer_cpf',
                'vendeai_leads.customer_name',
                'vendeai_leads.customer_birth_date',
                'vendeai_leads.customer_phone',
                'vendeai_leads.customer_email',
                'vendeai_leads.simulation_product',
                'vendeai_leads.simulation_bank',
                'vendeai_leads.simulation_liquid_value',
                'vendeai_leads.simulation_number_of_payments',
                'vendeai_leads.simulation_installment_value',
                'vendeai_leads.simulation_monthly_fee',
                'vendeai_leads.simulation_table_name',
                'vendeai_leads.simulation_table_id',
                'vendeai_leads.simulation_best_liquid_value',
                'vendeai_leads.simulation_best_table_id',
                'vendeai_leads.simulation_table_details',
                'vendeai_leads.simulation_received_at',
                'vendeai_leads.proposal_product',
                'vendeai_leads.proposal_bank',
                'vendeai_leads.proposal_id',
                'vendeai_leads.proposal_number',
                'vendeai_leads.proposal_status',
                'vendeai_leads.previous_proposal_status',
                'vendeai_leads.proposal_liquid_value',
                'vendeai_leads.proposal_gross_value',
                'vendeai_leads.proposal_number_of_payments',
                'vendeai_leads.proposal_installment_value',
                'vendeai_leads.proposal_table_name',
                'vendeai_leads.proposal_table_id',
                'vendeai_leads.proposal_formalization_link',
                'vendeai_leads.proposal_created_at',
                'vendeai_leads.proposal_status_updated_at',
                'attempts.newcorban_proposta_id',
                'attempts.newcorban_error',
                'attempts.newcorban_sent_at',
            ]);

        self::applyDateFilter($query, 'vendeai_leads.first_received_at', $from, $to);

        if (in_array(($filters['newcorban_filter'] ?? 'all'), ['sent', 'created'], true)) {
            $query->whereNotNull('attempts.newcorban_sent_at');
        }

        return $query->orderBy('vendeai_leads.first_received_at', $direction)->orderBy('vendeai_leads.id', $direction);
    }

    private static function buildAttemptsQuery(array $filters): Builder
    {
        [$from, $to] = VendeaiDateRange::fromValidated($filters);
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('vendeai_newcorban_proposal_attempts as attempts')
            ->leftJoin('vendeai_leads as leads', 'leads.id', '=', 'attempts.vendeai_lead_id')
            ->select([
                'attempts.id',
                'attempts.received_at',
                'attempts.newcorban_sent_at',
                'attempts.newcorban_response_status',
                'attempts.newcorban_error',
                'attempts.newcorban_proposta_id',
                'attempts.newcorban_cliente_id',
                'leads.account_id',
                'leads.chat_id',
                'leads.customer_cpf',
                'leads.customer_name',
                'leads.customer_birth_date',
                'leads.customer_phone',
                'leads.proposal_id',
                'leads.proposal_bank',
                'leads.proposal_product',
                'leads.proposal_status',
                'leads.proposal_liquid_value',
                'leads.proposal_gross_value',
                'leads.proposal_number_of_payments',
                'leads.proposal_installment_value',
                'leads.proposal_table_id',
            ])
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(attempts.newcorban_request_payload, '$.content.proposta.banco_id')) as request_banco_id")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(attempts.newcorban_request_payload, '$.content.proposta.produto_id')) as request_produto_id")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(attempts.newcorban_request_payload, '$.content.proposta.convenio_id')) as request_convenio_id")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(attempts.newcorban_request_payload, '$.content.proposta.promotora_id')) as request_promotora_id")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(attempts.newcorban_request_payload, '$.content.proposta.origem_id')) as request_origem_id")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(attempts.newcorban_request_payload, '$.content.proposta.vendedor')) as request_vendedor")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(attempts.newcorban_request_payload, '$.content.proposta.login_digitacao')) as request_login_digitacao");

        self::applyDateFilter($query, 'attempts.received_at', $from, $to);
        self::applyAttemptStatusFilter($query, (string) ($filters['status'] ?? 'all'));

        return $query->orderBy('attempts.received_at', $direction)->orderBy('attempts.id', $direction);
    }

    private static function applyDateFilter(Builder $query, string $column, ?Carbon $from, ?Carbon $to): void
    {
        if ($from !== null) {
            $query->where($column, '>=', $from);
        }

        if ($to !== null) {
            $query->where($column, '<=', $to);
        }
    }

    private static function applyAttemptStatusFilter(Builder $query, string $status): void
    {
        match ($status) {
            'success' => $query->whereNotNull('attempts.newcorban_proposta_id'),
            'failed' => $query
                ->whereNull('attempts.newcorban_proposta_id')
                ->whereNotNull('attempts.newcorban_sent_at'),
            'pending' => $query->whereNull('attempts.newcorban_sent_at'),
            default => null,
        };
    }

    private static function mapLead(object $lead): array
    {
        return [
            self::sanitizeCsvValue(self::csvCpf($lead->customer_cpf ?? null)),
            self::sanitizeCsvValue($lead->customer_name ?? null),
            self::sanitizeCsvValue(self::formatDate($lead->customer_birth_date ?? null)),
            self::sanitizeCsvValue(self::csvPhone($lead->customer_phone ?? null)),
            self::sanitizeCsvValue($lead->customer_email ?? null),
            self::sanitizeCsvValue($lead->account_id ?? null),
            self::sanitizeCsvValue($lead->chat_id ?? null),
            self::sanitizeCsvValue($lead->id ?? null),
            self::sanitizeCsvValue($lead->chat_product ?? null),
            self::sanitizeCsvValue($lead->stage ?? null),
            self::sanitizeCsvValue($lead->tags ?? null),
            self::sanitizeCsvValue($lead->last_event ?? null),
            self::sanitizeCsvValue($lead->simulation_product ?? null),
            self::sanitizeCsvValue($lead->simulation_bank ?? null),
            self::sanitizeCsvValue($lead->simulation_liquid_value ?? null),
            self::sanitizeCsvValue($lead->simulation_number_of_payments ?? null),
            self::sanitizeCsvValue($lead->simulation_installment_value ?? null),
            self::sanitizeCsvValue($lead->simulation_monthly_fee ?? null),
            self::sanitizeCsvValue($lead->simulation_table_name ?? null),
            self::sanitizeCsvValue($lead->simulation_table_id ?? null),
            self::sanitizeCsvValue($lead->simulation_best_liquid_value ?? null),
            self::sanitizeCsvValue($lead->simulation_best_table_id ?? null),
            self::sanitizeCsvValue($lead->simulation_table_details ?? null),
            self::sanitizeCsvValue(self::formatDateTime($lead->simulation_received_at ?? null)),
            self::sanitizeCsvValue($lead->proposal_product ?? null),
            self::sanitizeCsvValue($lead->proposal_bank ?? null),
            self::sanitizeCsvValue($lead->proposal_id ?? null),
            self::sanitizeCsvValue($lead->proposal_number ?? null),
            self::sanitizeCsvValue($lead->proposal_status ?? null),
            self::sanitizeCsvValue($lead->previous_proposal_status ?? null),
            self::sanitizeCsvValue($lead->proposal_liquid_value ?? null),
            self::sanitizeCsvValue($lead->proposal_gross_value ?? null),
            self::sanitizeCsvValue($lead->proposal_number_of_payments ?? null),
            self::sanitizeCsvValue($lead->proposal_installment_value ?? null),
            self::sanitizeCsvValue($lead->proposal_table_name ?? null),
            self::sanitizeCsvValue($lead->proposal_table_id ?? null),
            self::sanitizeCsvValue($lead->proposal_formalization_link ?? null),
            self::sanitizeCsvValue(self::formatDateTime($lead->proposal_created_at ?? null)),
            self::sanitizeCsvValue(self::formatDateTime($lead->proposal_status_updated_at ?? null)),
            self::sanitizeCsvValue($lead->newcorban_proposta_id ?? null),
            self::sanitizeCsvValue($lead->newcorban_error ?? null),
            self::sanitizeCsvValue(self::formatDateTime($lead->newcorban_sent_at ?? null)),
            self::sanitizeCsvValue(self::formatDateTime($lead->first_received_at ?? null)),
            self::sanitizeCsvValue(self::formatDateTime($lead->last_received_at ?? null)),
        ];
    }

    private static function mapAttempt(object $attempt): array
    {
        return [
            self::sanitizeCsvValue(self::csvCpf($attempt->customer_cpf ?? null)),
            self::sanitizeCsvValue($attempt->customer_name ?? null),
            self::sanitizeCsvValue(self::formatDate($attempt->customer_birth_date ?? null)),
            self::sanitizeCsvValue(self::csvPhone($attempt->customer_phone ?? null)),
            self::sanitizeCsvValue($attempt->account_id ?? null),
            self::sanitizeCsvValue($attempt->chat_id ?? null),
            self::sanitizeCsvValue($attempt->proposal_id ?? null),
            self::sanitizeCsvValue($attempt->proposal_bank ?? null),
            self::sanitizeCsvValue($attempt->proposal_product ?? null),
            self::sanitizeCsvValue($attempt->proposal_status ?? null),
            self::sanitizeCsvValue($attempt->proposal_liquid_value ?? null),
            self::sanitizeCsvValue($attempt->proposal_gross_value ?? null),
            self::sanitizeCsvValue($attempt->proposal_number_of_payments ?? null),
            self::sanitizeCsvValue($attempt->proposal_installment_value ?? null),
            self::sanitizeCsvValue($attempt->proposal_table_id ?? null),
            self::sanitizeCsvValue($attempt->request_banco_id ?? null),
            self::sanitizeCsvValue($attempt->request_produto_id ?? null),
            self::sanitizeCsvValue($attempt->request_convenio_id ?? null),
            self::sanitizeCsvValue($attempt->request_promotora_id ?? null),
            self::sanitizeCsvValue($attempt->request_origem_id ?? null),
            self::sanitizeCsvValue($attempt->request_vendedor ?? null),
            self::sanitizeCsvValue($attempt->request_login_digitacao ?? null),
            self::sanitizeCsvValue($attempt->id ?? null),
            self::sanitizeCsvValue(self::attemptStatus($attempt)),
            self::sanitizeCsvValue($attempt->newcorban_proposta_id ?? null),
            self::sanitizeCsvValue($attempt->newcorban_cliente_id ?? null),
            self::sanitizeCsvValue($attempt->newcorban_response_status ?? null),
            self::sanitizeCsvValue($attempt->newcorban_error ?? null),
            self::sanitizeCsvValue(self::formatDateTime($attempt->received_at ?? null)),
            self::sanitizeCsvValue(self::formatDateTime($attempt->newcorban_sent_at ?? null)),
        ];
    }

    private static function attemptStatus(object $attempt): string
    {
        if (($attempt->newcorban_proposta_id ?? null) !== null) {
            return 'success';
        }

        if (($attempt->newcorban_sent_at ?? null) === null) {
            return 'pending';
        }

        return 'failed';
    }

    private static function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i:s');
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value, 'UTC')->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i:s');
            } catch (\Throwable) {
                return $value;
            }
        }

        return null;
    }

    private static function formatDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('d/m/Y');
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->format('d/m/Y');
            } catch (\Throwable) {
                return $value;
            }
        }

        return null;
    }

    private static function sanitizeCsvValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Sim' : 'Nao';
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $string = (string) $value;
        if ($string === '') {
            return '';
        }

        if (preg_match('/^\s*[=+\-@]/', $string) === 1) {
            return "'" . $string;
        }

        return $string;
    }

    private static function csvCpf(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);

        if ($digits === null || $digits === '') {
            return null;
        }

        $trimmed = ltrim($digits, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    private static function csvPhone(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);

        if ($digits === null || $digits === '') {
            return null;
        }

        return $digits;
    }
}
