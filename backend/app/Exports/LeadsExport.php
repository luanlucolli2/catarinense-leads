<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;

class LeadsExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithColumnFormatting
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
            'cpf'               => 'CPF',
            'nome'              => 'Nome',
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
        ];

        return array_map(fn($c) => $map[$c], $this->columns);
    }

    public function map($lead): array
    {
        $row = [];

        foreach ($this->columns as $col) {
            switch ($col) {
                case 'cpf':
                    $row[] = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $lead->cpf);
                    break;
                case 'status':
                    $row[] = ($lead->consulta === 'Saldo FACTA' && $lead->libera > 0)
                              ? 'Elegível'
                              : 'Inelegível';
                    break;
                case 'data_atualizacao':
                    $row[] = optional($lead->data_atualizacao)->format('d/m/Y');
                    break;
                case 'saldo':
                case 'libera':
                    // mantém número para permitir formatação no Excel
                    $val = $lead->{$col};
                    $row[] = is_numeric($val) ? (float) $val : $val;
                    break;
                default:
                    $row[] = $lead->{$col};
            }
        }

        return $row;
    }

    public function columnFormats(): array
    {
        // aplica formatação numérica/data a colunas específicas
        $formats = [];
        foreach ($this->columns as $idx => $col) {
            $colIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            if (in_array($col, ['saldo','libera'])) {
                $formats[$colIndex] = NumberFormat::FORMAT_NUMBER_00;
            }
            if ($col === 'data_atualizacao') {
                $formats[$colIndex] = NumberFormat::FORMAT_DATE_DDMMYYYY;
            }
        }
        return $formats;
    }
}
