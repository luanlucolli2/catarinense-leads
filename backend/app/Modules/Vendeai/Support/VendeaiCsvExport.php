<?php

declare(strict_types=1);

namespace App\Modules\Vendeai\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class VendeaiCsvExport
{
    public const TYPE_LEADS = 'leads';

    public static function filenamePrefix(string $type): string
    {
        return 'vendeai_leads';
    }

    public static function headings(string $type): array
    {
        return [
            'CPF',
            'Nome',
            'Data nascimento',
            'Telefone',
            'Numero IA',
            'Chat ID',
            'Produto conversa',
            'Stage',
            'Tags',
            'Produto simulacao',
            'Banco simulacao',
            'Valor liquido simulacao',
            'Quantidade parcelas simulacao',
            'Valor parcela simulacao',
            'Taxa mensal simulacao',
            'Nome tabela simulacao',
            'ID tabela simulacao',
            'Melhor valor liquido simulacao',
            'Data simulacao',
            'Produto proposta',
            'Banco proposta',
            'Proposal ID',
            'Numero proposta',
            'Status proposta',
            'Valor liquido proposta',
            'Valor bruto proposta',
            'Quantidade parcelas proposta',
            'Valor parcela proposta',
            'Nome tabela proposta',
            'ID tabela proposta',
            'Data criacao proposta',
            'Data atualizacao status proposta',
            'Proposta NewCorban',
            'Erro NewCorban',
            'Enviado NewCorban em',
            'Payload proposta VendeAI',
            'Payload NewCorban',
            'Resposta NewCorban',
            'Primeiro evento em',
            'Ultimo evento em',
        ];
    }

    /**
     * @param resource $fh
     */
    public static function writeRows($fh, string $type, array $filters, string $delimiter, string $enclosure, int $flushEvery): int
    {
        $query = self::buildLeadsQuery($filters);

        $written = 0;

        foreach ($query->cursor() as $row) {
            fputcsv(
                $fh,
                self::mapLead($row),
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
        $leadPeriodColumn = VendeaiLeadFilters::leadPeriodColumn($filters);
        $leadFilters = $filters;

        if (in_array(($filters['newcorban_filter'] ?? 'all'), ['sent', 'created'], true) && ! isset($filters['newcorban_status'])) {
            $filters['newcorban_status'] = 'sent';
        }
        unset($leadFilters['newcorban_status']);

        $latestAttempts = VendeaiLeadFilters::latestAttemptsSubquery();

        $query = DB::table('vendeai_leads')
            ->leftJoinSub($latestAttempts, 'latest_attempts', function ($join) {
                $join->on('latest_attempts.vendeai_lead_id', '=', 'vendeai_leads.id');
            })
            ->leftJoin('vendeai_newcorban_proposal_attempts as filter_attempts', 'filter_attempts.id', '=', 'latest_attempts.id')
            ->leftJoin('vendeai_newcorban_proposal_attempts as attempts', function ($join) use ($filters, $from, $to) {
                $join->on('attempts.vendeai_lead_id', '=', 'vendeai_leads.id');
                VendeaiLeadFilters::applyAttemptStatusFilter($join, $filters['newcorban_status'] ?? null, 'attempts');

                if ($from !== null) {
                    $join->where('attempts.received_at', '>=', $from);
                }

                if ($to !== null) {
                    $join->where('attempts.received_at', '<=', $to);
                }
            })
            ->select([
                'vendeai_leads.id',
                'vendeai_leads.account_id',
                'vendeai_leads.chat_id',
                'vendeai_leads.product_key',
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
                'vendeai_leads.inbox_phone_number',
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
                'vendeai_leads.proposal_created_at',
                'vendeai_leads.proposal_status_updated_at',
                'attempts.newcorban_proposta_id',
                'attempts.newcorban_error',
                'attempts.newcorban_sent_at',
                'attempts.received_at as attempt_received_at',
                'attempts.raw_payload',
                'attempts.newcorban_request_payload',
                'attempts.newcorban_response_body',
            ]);

        VendeaiLeadFilters::applyFilters($query, $leadFilters, [
            'lead_alias' => 'vendeai_leads',
            'attempt_alias' => 'filter_attempts',
            'date_column' => $leadPeriodColumn,
            'from' => $from,
            'to' => $to,
        ]);
        VendeaiLeadFilters::applyConversationAttemptStatusFilter($query, $filters['newcorban_status'] ?? null, 'vendeai_leads');

        return $query
            ->orderBy($leadPeriodColumn, $direction)
            ->orderBy('vendeai_leads.id', $direction)
            ->orderBy('attempts.received_at', $direction)
            ->orderBy('attempts.id', $direction);
    }

    private static function mapLead(object $lead): array
    {
        $attemptProposal = \App\Modules\Vendeai\Support\VendeaiAttemptPayload::proposal($lead->raw_payload ?? null);
        $proposalProduct = $attemptProposal['proposal_product'] ?? $lead->proposal_product ?? null;
        $proposalBank = $attemptProposal['proposal_bank'] ?? $lead->proposal_bank ?? null;
        $proposalId = $attemptProposal['proposal_id'] ?? $lead->proposal_id ?? null;
        $proposalNumber = $attemptProposal['proposal_number'] ?? $lead->proposal_number ?? null;
        $proposalStatus = $attemptProposal['proposal_status'] ?? $lead->proposal_status ?? null;
        $proposalLiquidValue = $attemptProposal['proposal_liquid_value'] ?? $lead->proposal_liquid_value ?? null;
        $proposalGrossValue = $attemptProposal['proposal_gross_value'] ?? $lead->proposal_gross_value ?? null;
        $proposalNumberOfPayments = $attemptProposal['proposal_number_of_payments'] ?? $lead->proposal_number_of_payments ?? null;
        $proposalInstallmentValue = $attemptProposal['proposal_installment_value'] ?? $lead->proposal_installment_value ?? null;
        $proposalTableName = $attemptProposal['proposal_table_name'] ?? $lead->proposal_table_name ?? null;
        $proposalTableId = $attemptProposal['proposal_table_id'] ?? $lead->proposal_table_id ?? null;
        $proposalCreatedAt = $lead->attempt_received_at ?? $lead->proposal_created_at ?? null;

        return [
            self::sanitizeCsvValue(self::csvCpf($lead->customer_cpf ?? null)),
            self::sanitizeCsvValue($lead->customer_name ?? null),
            self::sanitizeCsvValue(self::formatDate($lead->customer_birth_date ?? null)),
            self::sanitizeCsvValue(self::csvPhone($lead->customer_phone ?? null)),
            self::sanitizeCsvValue(self::csvPhone($lead->inbox_phone_number ?? null)),
            self::sanitizeCsvValue($lead->chat_id ?? null),
            self::sanitizeCsvValue($lead->chat_product ?? null),
            self::sanitizeCsvValue($lead->stage ?? null),
            self::sanitizeCsvValue($lead->tags ?? null),
            self::sanitizeCsvValue($lead->simulation_product ?? null),
            self::sanitizeCsvValue($lead->simulation_bank ?? null),
            self::sanitizeCsvValue($lead->simulation_liquid_value ?? null),
            self::sanitizeCsvValue($lead->simulation_number_of_payments ?? null),
            self::sanitizeCsvValue($lead->simulation_installment_value ?? null),
            self::sanitizeCsvValue($lead->simulation_monthly_fee ?? null),
            self::sanitizeCsvValue($lead->simulation_table_name ?? null),
            self::sanitizeCsvValue($lead->simulation_table_id ?? null),
            self::sanitizeCsvValue($lead->simulation_best_liquid_value ?? null),
            self::sanitizeCsvValue(self::formatDateTime($lead->simulation_received_at ?? null)),
            self::sanitizeCsvValue($proposalProduct),
            self::sanitizeCsvValue($proposalBank),
            self::sanitizeCsvValue($proposalId),
            self::sanitizeCsvValue($proposalNumber),
            self::sanitizeCsvValue($proposalStatus),
            self::sanitizeCsvValue($proposalLiquidValue),
            self::sanitizeCsvValue($proposalGrossValue),
            self::sanitizeCsvValue($proposalNumberOfPayments),
            self::sanitizeCsvValue($proposalInstallmentValue),
            self::sanitizeCsvValue($proposalTableName),
            self::sanitizeCsvValue($proposalTableId),
            self::sanitizeCsvValue(self::formatDateTime($proposalCreatedAt)),
            self::sanitizeCsvValue(self::formatDateTime($lead->proposal_status_updated_at ?? null)),
            self::sanitizeCsvValue($lead->newcorban_proposta_id ?? null),
            self::sanitizeCsvValue($lead->newcorban_error ?? null),
            self::sanitizeCsvValue(self::formatDateTime($lead->newcorban_sent_at ?? null)),
            self::sanitizeCsvValue(self::csvJsonPayload($lead->raw_payload ?? null)),
            self::sanitizeCsvValue(self::csvNewcorbanPayload($lead->newcorban_request_payload ?? null)),
            self::sanitizeCsvValue(self::csvJsonPayload($lead->newcorban_response_body ?? null)),
            self::sanitizeCsvValue(self::formatDateTime($lead->first_received_at ?? null)),
            self::sanitizeCsvValue(self::formatDateTime($lead->last_received_at ?? null)),
        ];
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

    private static function csvNewcorbanPayload(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        if (is_array($value)) {
            if (isset($value['auth']) && is_array($value['auth']) && array_key_exists('password', $value['auth'])) {
                $value['auth']['password'] = null;
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        return (string) $value;
    }

    private static function csvJsonPayload(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        return (string) $value;
    }
}
