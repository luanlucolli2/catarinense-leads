<?php

namespace App\Imports;

use App\Models\ImportJob;
use App\Support\Cpf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Row;
use Maatwebsite\Excel\Events\AfterChunk;
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Events\BeforeImport;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Carbon\Carbon;

class CltSnapshotImport implements OnEachRow, WithHeadingRow, WithChunkReading, WithEvents, ShouldQueue
{
    protected ImportJob $importJob;

    /** buffer por CPF com melhor vínculo e/ou not_found */
    private array $buf = []; // cpf => ['best' => [...], 'not_found' => bool]
    private int $rowsInCurrentChunk = 0;

    public function __construct(ImportJob $importJob)
    {
        $this->importJob = $importJob;
    }

    public function onRow(Row $row)
    {
        $r = $row->toArray();
        $this->rowsInCurrentChunk++;

        // CPF
        $cpfRaw = $r['cpf'] ?? ($r['c_p_f'] ?? null);
        $cpf = Cpf::normalize((string) $cpfRaw);
        if ($cpf === null)
            return;

        $msg = trim((string) ($r['mensagem'] ?? ($r['status_code'] ?? '')));
        $isNotFound = $this->isNaoEncontradoMessage($msg);

        // vínculo?
        $dataAdm = $this->parseDateCell($r['data_de_admissao'] ?? ($r['data_admissao'] ?? null));
        $temVinculo = !is_null($dataAdm);

        if (!isset($this->buf[$cpf])) {
            $this->buf[$cpf] = ['best' => null, 'not_found' => false];
        }

        if ($temVinculo) {
            // leitura robusta do cabeçalho "Qtde Empréstimos Ativos Suspensos"
            $emsRaw = $this->cell($r, [
                'qtde_empréstimos_ativos_suspensos',
                'qtde_emprestimos_ativos_suspensos',
                'qtde-emprestimos-ativos-suspensos',
                'qtde emprestimos ativos suspensos',
            ]);

            // margem disponível
            $margemDisp = $this->toFloat($r['margem_disponivel'] ?? null);
            // valor máximo = 70% da margem disponível
            $valorMax = is_null($margemDisp) ? null : round($margemDisp * 0.70, 2);

            $cand = [
                'cpf' => $cpf,
                'nome' => $this->cleanName($r['nome'] ?? null),
                'eleg' => $this->simNaoToBool($r['elegivel'] ?? null),

                'dt_nasc' => $this->parseDateCell($r['data_de_nascimento'] ?? null),
                'idade' => $this->computeIdadeAnos($this->parseDateCell($r['data_de_nascimento'] ?? null)),
                'sexo' => $this->nullableString($r['sexo'] ?? null),

                'dt_adm' => $dataAdm,
                'meses_adm' => $this->computeTempoAdmissaoMeses(
                    $dataAdm,
                    $this->parseDateCell($r['data_de_desligamento'] ?? null)
                ),

                'vrenda' => $this->toFloat($r['valor_da_renda'] ?? null),
                'vbase' => $this->toFloat($r['valor_base_da_margem'] ?? null),
                'margem' => $margemDisp,
                'vmax' => $valorMax,

                'cat_cod' => $this->nullableString($r['categoria_do_trabalhador_código'] ?? ($r['categoria_do_trabalhador_codigo'] ?? ($r['categoria_do_trabalhador__código_'] ?? null))),
                'inicio_emp' => $this->parseDateCell($r['início_da_atividade_do_empregador'] ?? ($r['inicio_da_atividade_do_empregador'] ?? null)),

                'qtd_ems' => $this->toInt($emsRaw),
                'legados' => $this->simNaoToBool($r['empréstimos_legados'] ?? ($r['emprestimos_legados'] ?? null)),
            ];

            $prev = $this->buf[$cpf]['best'];
            if ($prev === null || $this->isDateGreater($cand['dt_adm'], $prev['dt_adm'])) {
                $this->buf[$cpf]['best'] = $cand;
            }
        } elseif ($isNotFound) {
            if ($this->buf[$cpf]['best'] === null) {
                $this->buf[$cpf]['not_found'] = true;
            }
        }
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function () {
                $this->rowsInCurrentChunk = 0;
                $this->buf = [];
            },
            AfterChunk::class => function () {
                $this->flushBuffer();
                if ($this->rowsInCurrentChunk > 0) {
                    DB::table('import_jobs')
                        ->where('id', $this->importJob->id)
                        ->update([
                            'processed_rows' => DB::raw('LEAST(processed_rows + ' . (int) $this->rowsInCurrentChunk . ', total_rows)')
                        ]);
                    $this->rowsInCurrentChunk = 0;
                }
            },
            AfterImport::class => function () {
                $this->flushBuffer();
                $this->rowsInCurrentChunk = 0;
                $this->importJob->update([
                    'processed_rows' => $this->importJob->total_rows,
                    'status' => 'concluido',
                    'finished_at' => now(),
                ]);
            },
        ];
    }

    /* ===================== Flush ===================== */

    private function flushBuffer(): void
    {
        if (empty($this->buf))
            return;

        $cpfsVinc = [];
        $cpfsNF = [];
        foreach ($this->buf as $cpf => $st) {
            if ($st['best'] !== null)
                $cpfsVinc[] = $cpf;
            elseif ($st['not_found'])
                $cpfsNF[] = $cpf;
        }

        if (!empty($cpfsVinc) || !empty($cpfsNF)) {
            $leadMap = DB::table('leads')
                ->whereIn('cpf', array_merge($cpfsVinc, $cpfsNF))
                ->pluck('id', 'cpf');

            $now = now();

            if (!empty($cpfsVinc)) {
                $rows = [];
                foreach ($cpfsVinc as $cpf) {
                    $b = $this->buf[$cpf]['best'];
                    $leadId = $leadMap[$cpf] ?? null;
                    if (!$leadId)
                        continue;

                    $rows[] = [
                        'cpf' => $cpf,
                        'lead_id' => $leadId,
                        'nome' => $b['nome'],
                        'elegivel' => $b['eleg'],
                        'data_nascimento' => $b['dt_nasc'],
                        'idade' => $b['idade'],
                        'sexo' => $b['sexo'],
                        'data_admissao' => $b['dt_adm'],
                        'meses_admissao' => $b['meses_adm'],
                        'valor_renda' => $b['vrenda'],
                        'valor_base_margem' => $b['vbase'],
                        'margem_disponivel' => $b['margem'],
                        'valor_max_prestacao' => $b['vmax'],
                        'categoria_trabalhador_codigo' => $b['cat_cod'],
                        'inicio_atividade_empregador' => $b['inicio_emp'],
                        'qtd_emprestimos_ativos_suspensos' => $b['qtd_ems'],
                        'emprestimos_legados' => $b['legados'],
                        'not_found' => 0,
                        'job_id' => $this->importJob->id,
                        'updated_at' => $now,
                    ];
                }

                $batch = 400;
                for ($i = 0; $i < count($rows); $i += $batch) {
                    $slice = array_slice($rows, $i, $batch);
                    $this->upsertVinculosConditional($slice);
                }
            }

            if (!empty($cpfsNF)) {
                $rowsNF = [];
                foreach ($cpfsNF as $cpf) {
                    $leadId = $leadMap[$cpf] ?? null;
                    if (!$leadId)
                        continue;
                    $rowsNF[] = [
                        'cpf' => $cpf,
                        'lead_id' => $leadId,
                        'not_found' => 1,
                        'job_id' => $this->importJob->id,
                        'updated_at' => $now,
                    ];
                }

                $batch = 800;
                for ($i = 0; $i < count($rowsNF); $i += $batch) {
                    $slice = array_slice($rowsNF, $i, $batch);
                    $this->insertIgnoreNotFound($slice);
                }
            }
        }

        $this->buf = [];
    }

    private function upsertVinculosConditional(array $rows): void
    {
        if (empty($rows))
            return;

        $cols = [
            'cpf',
            'lead_id',
            'nome',
            'elegivel',
            'data_nascimento',
            'idade',
            'sexo',
            'data_admissao',
            'meses_admissao',
            'valor_renda',
            'valor_base_margem',
            'margem_disponivel',
            'valor_max_prestacao',
            'categoria_trabalhador_codigo',
            'inicio_atividade_empregador',
            'qtd_emprestimos_ativos_suspensos',
            'emprestimos_legados',
            'not_found',
            'job_id',
            'updated_at'
        ];

        $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $values = [];
        $rowsSql = [];
        foreach ($rows as $r) {
            $rowsSql[] = $placeholders;
            foreach ($cols as $c) {
                $values[] = $r[$c] ?? null;
            }
        }

        $cond = "IFNULL(VALUES(data_admissao),'1000-01-01') >= IFNULL(data_admissao,'1000-01-01')";

        $sets = [
            "lead_id = IF(lead_id IS NULL, VALUES(lead_id), IF({$cond}, VALUES(lead_id), lead_id))",
            "nome = IF({$cond}, VALUES(nome), nome)",
            "elegivel = IF({$cond}, VALUES(elegivel), elegivel)",
            "data_nascimento = IF({$cond}, VALUES(data_nascimento), data_nascimento)",
            "idade = IF({$cond}, VALUES(idade), idade)",
            "sexo = IF({$cond}, VALUES(sexo), sexo)",
            "data_admissao = IF({$cond}, VALUES(data_admissao), data_admissao)",
            "meses_admissao = IF({$cond}, VALUES(meses_admissao), meses_admissao)",
            "valor_renda = IF({$cond}, VALUES(valor_renda), valor_renda)",
            "valor_base_margem = IF({$cond}, VALUES(valor_base_margem), valor_base_margem)",
            "margem_disponivel = IF({$cond}, VALUES(margem_disponivel), margem_disponivel)",
            "valor_max_prestacao = IF({$cond}, VALUES(valor_max_prestacao), valor_max_prestacao)",
            "categoria_trabalhador_codigo = IF({$cond}, VALUES(categoria_trabalhador_codigo), categoria_trabalhador_codigo)",
            "inicio_atividade_empregador = IF({$cond}, VALUES(inicio_atividade_empregador), inicio_atividade_empregador)",
            "qtd_emprestimos_ativos_suspensos = IF({$cond}, VALUES(qtd_emprestimos_ativos_suspensos), qtd_emprestimos_ativos_suspensos)",
            "emprestimos_legados = IF({$cond}, VALUES(emprestimos_legados), emprestimos_legados)",
            "not_found = IF({$cond}, 0, not_found)",
            "job_id = IF({$cond}, VALUES(job_id), job_id)",
            "updated_at = IF({$cond}, VALUES(updated_at), updated_at)",
        ];

        $sql = "INSERT INTO clt_snapshots (" . implode(',', $cols) . ") VALUES "
            . implode(',', $rowsSql)
            . " ON DUPLICATE KEY UPDATE " . implode(', ', $sets);

        DB::statement($sql, $values);
    }

    private function insertIgnoreNotFound(array $rows): void
    {
        if (empty($rows))
            return;
        $cols = ['cpf', 'lead_id', 'not_found', 'job_id', 'updated_at'];
        $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $values = [];
        $rowsSql = [];
        foreach ($rows as $r) {
            $rowsSql[] = $placeholders;
            foreach ($cols as $c) {
                $values[] = $r[$c] ?? null;
            }
        }
        $sql = "INSERT IGNORE INTO clt_snapshots (" . implode(',', $cols) . ") VALUES " . implode(',', $rowsSql);
        DB::statement($sql, $values);
    }

    /* ===================== Helpers ===================== */

    private function cleanName(?string $s): ?string
    {
        if ($s === null)
            return null;
        $s = trim($s);
        $s = preg_replace('/[^\p{L}\p{N} \'\-]/u', '', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s !== '' ? $s : null;
    }

    private function nullableString($v): ?string
    {
        if ($v === null)
            return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function parseDateCell($v): ?string
    {
        if ($v === null || $v === '')
            return null;
        if (is_numeric($v)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject($v))->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        $s = trim((string) $v);
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $s)) {
            try {
                return Carbon::createFromFormat('d/m/Y', $s)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s;
        }
        return null;
    }

    private function computeIdadeAnos(?string $ymd): ?int
    {
        if (!$ymd)
            return null;
        try {
            return Carbon::parse($ymd)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    private function computeTempoAdmissaoMeses(?string $admissaoYmd, ?string $desligYmd): ?int
    {
        try {
            if (!$admissaoYmd)
                return null;
            $a = Carbon::parse($admissaoYmd);
            $b = $desligYmd ? Carbon::parse($desligYmd) : Carbon::now('America/Sao_Paulo');
            if ($b->lt($a))
                return 0;
            return $a->diffInMonths($b);
        } catch (\Throwable) {
            return null;
        }
    }

    private function toFloat($val): ?float
    {
        if ($val === null || $val === '')
            return null;
        if (is_numeric($val))
            return (float) $val;
        $s = preg_replace('/[^\d,.-]/', '', (string) $val);
        $s = str_replace(['.', ' '], ['', ''], $s);
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float) $s : null;
    }

    private function toInt($v): ?int
    {
        if ($v === null || $v === '')
            return null;
        if (is_numeric($v))
            return (int) $v;
        $d = preg_replace('/\D+/', '', (string) $v ?? '') ?? '';
        return $d !== '' ? (int) $d : null;
    }

    private function simNaoToBool($val): ?bool
    {
        if (is_bool($val))
            return $val;
        if ($val === null)
            return null;

        if (is_int($val) || is_float($val) || (is_string($val) && is_numeric($val))) {
            $n = (int) $val;
            if ($n === 1)
                return true;
            if ($n === 0)
                return false;
        }

        $s = trim((string) $val);
        if ($s === '')
            return null;

        $u = function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
        $uAscii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $u);
        if ($uAscii === false || $uAscii === null)
            $uAscii = $u;
        $uAscii = preg_replace('/\s+/', '', $uAscii);

        $truthy = ['SIM', 'S', 'TRUE', 'T', 'YES', 'Y'];
        $falsy = ['NAO', 'N', 'FALSE', 'F', 'NO'];

        if (in_array($uAscii, $truthy, true))
            return true;
        if (in_array($uAscii, $falsy, true))
            return false;
        return null;
    }

    private function isDateGreater(?string $a, ?string $b): bool
    {
        if ($a && !$b)
            return true;
        if (!$a || !$b)
            return false;
        return strcmp($a, $b) > 0;
    }

    private function isNaoEncontradoMessage(string $mensagem): bool
    {
        $msg = $this->normalizeStr($mensagem);
        if ($msg === '')
            return false;
        if ($msg === 'cpf nao encontrado na base')
            return true;
        return str_contains($msg, 'nao encontrado na base') || str_contains($msg, 'não encontrado na base');
    }

    private function normalizeStr(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $map = ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c'];
        $s = strtr($s, $map);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Busca por aliases e por chave “normalizada” em ASCII sem separadores. */
    private function cell(array $row, array $aliases)
    {
        foreach ($aliases as $k) {
            if (array_key_exists($k, $row))
                return $row[$k];
        }
        $norm = function (string $s): string {
            $s = mb_strtolower($s, 'UTF-8');
            $map = ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c'];
            $s = strtr($s, $map);
            $s = preg_replace('/[^a-z0-9]+/i', '', $s) ?? $s;
            return $s;
        };
        $want = $norm($aliases[0] ?? '');
        foreach ($row as $key => $val) {
            if ($norm((string) $key) === $want)
                return $val;
        }
        return null;
    }
}
