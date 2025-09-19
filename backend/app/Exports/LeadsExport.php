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

class LeadsExport implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting
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
            'id'                => 'ID',
            'cpf'               => 'CPF',
            'nome'              => 'Nome',
            'data_nascimento'   => 'Data de Nascimento',
            'fone1'             => 'Telefone 1',
            'fone2'             => 'Telefone 2',
            'fone3'             => 'Telefone 3',
            'fone4'             => 'Telefone 4',
            'classe_fone1'      => 'Classe 1',
            'classe_fone2'      => 'Classe 2',
            'classe_fone3'      => 'Classe 3',
            'classe_fone4'      => 'Classe 4',
            'status'            => 'Status',
            'consulta'          => 'Motivo (Consulta)',
            'saldo'             => 'Saldo',
            'libera'            => 'Libera',
            'primeira_origem'   => 'Origem',
            'data_atualizacao'  => 'Data de Atualização',
            'contracts_count'   => 'Qtde de Contratos',
        ];

        return array_map(fn($c) => $map[$c], $this->columns);
    }

    public function map($lead): array
    {
        $row = [];

        foreach ($this->columns as $col) {
            switch ($col) {
                case 'cpf':
                    // 👉 CPF como número (sem zeros à esquerda)
                    $row[] = $this->cpfToNumber($lead->cpf);
                    break;

                case 'status':
                    $isElegivel = isset($lead->status_flag)
                        ? ((int) $lead->status_flag === 1)
                        : ($this->toFloat($lead->libera) > 0 && trim((string) $lead->consulta) === 'Saldo FACTA');

                    $row[] = $isElegivel ? 'Elegível' : 'Inelegível';
                    break;

                case 'data_atualizacao':
                    // Data/hora → serial Excel (formatada como data)
                    $row[] = $this->toExcelDate($lead->data_atualizacao);
                    break;

                case 'data_nascimento':
                    // Data (somente dia) → serial Excel
                    $row[] = $this->toExcelDate($lead->data_nascimento, true);
                    break;

                case 'saldo':
                case 'libera':
                    $row[] = $this->toFloat($lead->{$col});
                    break;

                case 'contracts_count':
                    $row[] = isset($lead->contracts_count) ? (int) $lead->contracts_count : null;
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

            // 💰 números com 2 casas para saldo/libera
            if (in_array($col, ['saldo', 'libera'], true)) {
                $formats[$colIndex] = NumberFormat::FORMAT_NUMBER_00;
            }

            // 📅 datas
            if (in_array($col, ['data_atualizacao', 'data_nascimento'], true)) {
                $formats[$colIndex] = NumberFormat::FORMAT_DATE_DDMMYYYY;
            }

            // 🆔 CPF como inteiro (sem separador, sem zeros à esquerda)
            if ($col === 'cpf') {
                $formats[$colIndex] = '0';
            }
        }

        return $formats;
    }

    private function toExcelDate($value, bool $isDateOnly = false): ?float
    {
        if (empty($value)) return null;

        $dt = $value instanceof \DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse($value);

        if ($isDateOnly) {
            $dt = $dt->startOfDay();
        }

        return ExcelDate::dateTimeToExcel($dt);
    }

    /**
     * Converte strings numéricas com ponto/vírgula para float.
     * Exemplos aceitos: "R$ 1.234,56", "1.234,56", "1,234.56", "1234,56", "1234.56".
     */
    private function toFloat($val): ?float
    {
        if ($val === null || $val === '') return null;

        // Mantém somente dígitos, sinais e separadores . ,
        $s = preg_replace('/[^0-9.,-]/', '', (string) $val);
        if ($s === '' || $s === null) return null;

        // Descobre qual é o último separador presente → esse será o separador decimal
        $lastDot   = strrpos($s, '.');
        $lastComma = strrpos($s, ',');

        if ($lastDot === false && $lastComma === false) {
            return is_numeric($s) ? (float) $s : null;
        }

        if ($lastDot !== false && $lastComma !== false) {
            $decimalSep = ($lastDot > $lastComma) ? '.' : ',';
        } elseif ($lastDot !== false) {
            $decimalSep = '.';
        } else {
            $decimalSep = ',';
        }

        $thousandSep = ($decimalSep === '.') ? ',' : '.';

        // Remove separadores de milhar e normaliza o separador decimal para ponto
        $normalized = str_replace($thousandSep, '', $s);
        $normalized = str_replace($decimalSep, '.', $normalized);

        // Se por algum motivo sobraram múltiplos pontos, mantém só o último como decimal
        if (substr_count($normalized, '.') > 1) {
            // remove todos os '.' que tenham outro '.' à direita (mantém o último)
            $normalized = preg_replace('/\.(?=.*\.)/', '', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /** CPF -> número (sem zeros à esquerda); 32-bit cai para float */
    private function cpfToNumber($val): int|float|null
    {
        if ($val === null || $val === '') return null;

        $digits = preg_replace('/\D+/', '', (string) $val) ?? '';
        $digits = ltrim($digits, '0');
        if ($digits === '') $digits = '0';

        // evita overflow em arquiteturas 32-bit
        if (PHP_INT_SIZE >= 8) {
            return (int) $digits;
        }
        return (float) $digits;
    }
}
