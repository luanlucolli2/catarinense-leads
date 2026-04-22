<?php

declare(strict_types=1);

namespace App\Modules\Uy3\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

final class Uy3CltCsvExport
{
    private const TYPE_WEBHOOK_CLT = 'LEADS_CLT';

    public static function headings(): array
    {
        return [
            'CPF',
            'Nome do trabalhador',
            'Status',
            'Elegivel para emprestimo',
            'Valor liberado',
            'Margem disponivel',
            'Numero de parcelas',
            'Recebido em',
            'Validade da solicitacao',
            'Data de nascimento',
            'Data de admissao',
            'ID do registro',
            'É MEI',
            'Em recuperacao judicial',
            'Pessoa exposta politicamente',
            'Dividas ativas FGTS',
        ];
    }

    public static function writeRows($fh, array $filters, string $delimiter, string $enclosure, int $flushEvery): int
    {
        $query = self::buildQuery($filters);
        $written = 0;

        self::streamQuery($query, $fh, $delimiter, $enclosure, $flushEvery, $written);

        return $written;
    }

    private static function buildQuery(array $filters): Builder
    {
        $sort = (string) ($filters['sort'] ?? 'received_at');
        if (! in_array($sort, ['received_at', 'id'], true)) {
            $sort = 'received_at';
        }

        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $period = (string) ($filters['period'] ?? '30d');
        if (! in_array($period, ['all', '24h', '7d', '30d', '90d'], true)) {
            $period = '30d';
        }

        [$from, $to] = Uy3PostQuery::resolveDateRange($filters, $period);

        $query = DB::table('uy3_webhook_posts')
            ->select(['id', 'received_at'])
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.cpf')) as cpf")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.nomeTrabalhador')) as nome_trabalhador")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.status')) as status")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.elegivelEmprestimo')) as elegivel_emprestimo")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.valorLiberado')) as valor_liberado")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.margemDisponivel')) as margem_disponivel")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.numeroParcelas')) as numero_parcelas")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.dataHoraValidadeSolicitacao')) as data_hora_validade_solicitacao")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.dataNascimento')) as data_nascimento")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.dataAdmissao')) as data_admissao")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.is_mei')) as is_mei")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.is_judicial_recovery')) as is_judicial_recovery")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.pessoaExpostaPoliticamente.Codigo')) as pep_codigo")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.active_fgts_debts')) as active_fgts_debts");

        if ($from !== null) {
            $query->where('received_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('received_at', '<=', $to);
        }

        Uy3PostQuery::applyTypeWebhookFilter($query, self::TYPE_WEBHOOK_CLT);

        $query->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }

        return $query;
    }

    private static function streamQuery(
        Builder $query,
        $fh,
        string $delimiter,
        string $enclosure,
        int $flushEvery,
        int &$written
    ): void {
        foreach ($query->cursor() as $post) {
            fputcsv($fh, self::mapRecord($post), $delimiter, $enclosure, '\\');
            $written++;

            if ($written % $flushEvery === 0) {
                fflush($fh);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }
    }

    private static function mapRecord(object $post): array
    {
        return [
            self::sanitizeCsvValue($post->cpf ?? null),
            self::sanitizeCsvValue($post->nome_trabalhador ?? null),
            self::sanitizeCsvValue($post->status ?? null),
            self::sanitizeCsvValue(self::toBoolPtBr($post->elegivel_emprestimo ?? null)),
            self::sanitizeCsvValue($post->valor_liberado ?? null),
            self::sanitizeCsvValue($post->margem_disponivel ?? null),
            self::sanitizeCsvValue($post->numero_parcelas ?? null),
            self::sanitizeCsvValue(self::formatReceivedAtFromUtc($post->received_at ?? null)),
            self::sanitizeCsvValue(self::formatDateTime($post->data_hora_validade_solicitacao ?? null)),
            self::sanitizeCsvValue(self::formatDate($post->data_nascimento ?? null)),
            self::sanitizeCsvValue(self::formatDate($post->data_admissao ?? null)),
            self::sanitizeCsvValue($post->id ?? null),
            self::sanitizeCsvValue(self::toBoolPtBr($post->is_mei ?? null)),
            self::sanitizeCsvValue(self::toBoolPtBr($post->is_judicial_recovery ?? null)),
            self::sanitizeCsvValue(self::toPepPtBr($post->pep_codigo ?? null)),
            self::sanitizeCsvValue($post->active_fgts_debts ?? null),
        ];
    }

    private static function formatReceivedAtFromUtc(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)
                ->setTimezone('America/Sao_Paulo')
                ->format('d/m/Y H:i:s');
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value, 'UTC')
                    ->setTimezone('America/Sao_Paulo')
                    ->format('d/m/Y H:i:s');
            } catch (\Throwable) {
                return self::formatDateTime($value);
            }
        }

        return null;
    }

    private static function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('d/m/Y H:i:s');
        }

        if (is_string($value) && trim($value) !== '') {
            $trimmed = trim($value);

            if (preg_match('/^\d{14}$/', $trimmed) === 1) {
                return self::formatCompactTimestamp($trimmed);
            }

            try {
                return Carbon::parse($value)->format('d/m/Y H:i:s');
            } catch (\Throwable) {
                return $value;
            }
        }

        return null;
    }

    private static function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        if (preg_match('/^\d{8}$/', $string) === 1) {
            return self::formatCompactDate($string);
        }

        try {
            return Carbon::parse($string)->format('d/m/Y');
        } catch (\Throwable) {
            return $string;
        }
    }

    private static function formatCompactDate(string $value): string
    {
        return sprintf(
            '%s/%s/%s',
            substr($value, 0, 2),
            substr($value, 2, 2),
            substr($value, 4, 4)
        );
    }

    private static function formatCompactTimestamp(string $value): string
    {
        return sprintf(
            '%s/%s/%s %s:%s:%s',
            substr($value, 0, 2),
            substr($value, 2, 2),
            substr($value, 4, 4),
            substr($value, 8, 2),
            substr($value, 10, 2),
            substr($value, 12, 2)
        );
    }

    private static function toBoolPtBr(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            return null;
        }

        return $parsed ? 'Sim' : 'Não';
    }

    private static function toPepPtBr(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 0 ? 'Não' : 'Sim';
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed !== null) {
            return $parsed ? 'Sim' : 'Não';
        }

        return null;
    }

    private static function sanitizeCsvValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
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
}
