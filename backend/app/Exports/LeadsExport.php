<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeExport;

class LeadsExport implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting, WithEvents
{
    protected Builder $query;
    protected array   $columns;

    public function __construct(Builder $query, array $columns)
    {
        $this->query   = $query;
        $this->columns = $columns;
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        $map = [
            'id'                         => 'ID',
            'cpf'                        => 'CPF',
            'nome'                       => 'Nome',
            'data_nascimento'            => 'Data de Nascimento',
            'fone1'                      => 'Telefone 1',
            'fone2'                      => 'Telefone 2',
            'fone3'                      => 'Telefone 3',
            'fone4'                      => 'Telefone 4',
            'classe_fone1'               => 'Classe 1',
            'classe_fone2'               => 'Classe 2',
            'classe_fone3'               => 'Classe 3',
            'classe_fone4'               => 'Classe 4',
            'status'                     => 'Status',
            'consulta'                   => 'Motivo (Consulta)',
            'saldo'                      => 'Saldo',
            'libera'                     => 'Libera',
            'ultima_origem_cadastral'    => 'Última Origem (Cadastral)',
            'ultima_origem_higienizacao' => 'Última Origem (Higienização)',
            'data_atualizacao'           => 'Data de Atualização',
            'contracts_count'            => 'Qtde de Contratos',
            'vendedor'                   => 'Vendedor',
            'data_contrato_recente'      => 'Data de Contrato (mais recente)',
            // ➕ FGTS OFF
            'fgts_off_authorized'        => 'FGTS OFF Autorizado',
            'fgts_off_consultado_em'     => 'FGTS OFF Consultado em',
        ];

        return array_map(static fn($c) => $map[$c] ?? $c, $this->columns);
    }

    public function map($lead): array
    {
        $row = [];

        foreach ($this->columns as $col) {
            switch ($col) {
                case 'cpf':
                    $row[] = $this->cpfToNumber($lead->cpf);
                    break;

                case 'status':
                    $isElegivel = isset($lead->status_flag)
                        ? ((int) $lead->status_flag === 1)
                        : ($this->toFloat($lead->libera) > 0 && trim((string) $lead->consulta) === 'Saldo FACTA');
                    $row[] = $isElegivel ? 'Elegível' : 'Inelegível';
                    break;

                case 'data_atualizacao':
                    $row[] = $this->toExcelDate($lead->data_atualizacao);
                    break;

                case 'data_nascimento':
                    $row[] = $this->toExcelDate($lead->data_nascimento, true);
                    break;

                case 'data_contrato_recente':
                    $row[] = $this->toExcelDate($lead->data_contrato_recente, true);
                    break;

                case 'saldo':
                case 'libera':
                    $row[] = $this->toFloat($lead->{$col});
                    break;

                case 'contracts_count':
                    $row[] = isset($lead->contracts_count) ? (int) $lead->contracts_count : null;
                    break;

                case 'fgts_off_authorized':
                    $val = $lead->fgts_off_authorized;
                    $row[] = $val === null ? null : ($val ? 'Sim' : 'Não');
                    break;

                case 'fgts_off_consultado_em':
                    $row[] = $this->toExcelDate($lead->fgts_off_consultado_em);
                    break;

                default:
                    $row[] = $lead->{$col};
            }
        }

        return $row;
    }

    public function columnFormats(): array
    {
        $formats = [];

        foreach ($this->columns as $idx => $col) {
            $colIndex = Coordinate::stringFromColumnIndex($idx + 1);

            if (in_array($col, ['saldo', 'libera'], true)) {
                $formats[$colIndex] = NumberFormat::FORMAT_NUMBER_00;
            }

            if (in_array($col, ['data_atualizacao', 'data_nascimento', 'data_contrato_recente', 'fgts_off_consultado_em'], true)) {
                $formats[$colIndex] = NumberFormat::FORMAT_DATE_DDMMYYYY;
            }

            if ($col === 'cpf') {
                $formats[$colIndex] = '0';
            }
        }

        return $formats;
    }

    public function registerEvents(): array
    {
        return [
            BeforeExport::class => function (BeforeExport $event) {
                $delegate = method_exists($event->writer, 'getDelegate')
                    ? $event->writer->getDelegate()
                    : null;

                if ($delegate instanceof \PhpOffice\PhpSpreadsheet\Writer\Xlsx
                    && method_exists($delegate, 'setUseInlineStrings')) {
                    $delegate->setUseInlineStrings(true);
                }
            },
        ];
    }

    private function toExcelDate($value, bool $isDateOnly = false): ?float
    {
        if (empty($value)) return null;

        try {
            $dt = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse((string)$value);

            if ($isDateOnly) {
                $dt = $dt->startOfDay();
            }

            return ExcelDate::dateTimeToExcel($dt);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function toFloat($val): ?float
    {
        if ($val === null || $val === '') return null;

        $s = preg_replace('/[^0-9.,-]/', '', (string)$val);
        if ($s === '') return null;

        $lastDot   = strrpos($s, '.');
        $lastComma = strrpos($s, ',');

        if ($lastDot === false && $lastComma === false) {
            return is_numeric($s) ? (float)$s : null;
        }

        $decimalSep  = ($lastDot !== false && $lastComma !== false)
            ? (($lastDot > $lastComma) ? '.' : ',')
            : (($lastDot !== false) ? '.' : ',');

        $thousandSep = ($decimalSep === '.') ? ',' : '.';

        $normalized = str_replace($thousandSep, '', $s);
        $normalized = str_replace($decimalSep, '.', $normalized);

        if (substr_count($normalized, '.') > 1) {
            $normalized = preg_replace('/\.(?=.*\.)/', '', $normalized);
        }

        return is_numeric($normalized) ? (float)$normalized : null;
    }

    private function cpfToNumber($val): int|float|null
    {
        if ($val === null || $val === '') return null;

        $digits = preg_replace('/\D+/', '', (string)$val) ?? '';
        $digits = ltrim($digits, '0');
        if ($digits === '') $digits = '0';

        return PHP_INT_SIZE >= 8 ? (int)$digits : (float)$digits;
    }
}
