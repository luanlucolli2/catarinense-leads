<?php

namespace App\Jobs;

use App\Http\Filters\LeadFilter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateLeadsExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public function __construct(
        public int $userId,
        public string $token,
        public array $payload,
        public int $ttlSeconds
    ) {
        $this->onQueue((string) env('PREVIEW_JOB_QUEUE', 'reports'));
    }

    public function handle(): void
    {
        $key = $this->cacheKey($this->userId, $this->token);

        // Alvos: uso de RAM mínimo e IO sequencial
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', env('LEADS_EXPORT_MEMORY', '256M'));
            @ini_set('max_execution_time', '0');
            @ini_set('zend.enable_gc', '1');
            @ini_set('output_buffering', '0');
        }

        // Desliga query log para não acumular memória
        try { DB::connection()->disableQueryLog(); } catch (\Throwable) {}

        // Pasta temporária de arquivos
        Config::set('excel.temporary_files.local_path', storage_path('framework/cache/excel-temp'));

        $diskName = (string) env('LEADS_EXPORT_DISK', 'local');
        $dir      = trim((string) env('LEADS_EXPORT_DIR', 'leads-exports'), '/');
        $filename = "leads_export_{$this->token}.csv";
        $path     = "{$dir}/{$filename}";
        $tmpPath  = "{$dir}/{$this->token}.tmp.csv";

        $delimiter = env('LEADS_EXPORT_CSV_DELIMITER', ',');
        $enclosure = env('LEADS_EXPORT_CSV_ENCLOSURE', '"');
        $writeBOM  = (bool) env('LEADS_EXPORT_CSV_BOM', false); // opcional para Excel

        $chunkSize = (int) env('LEADS_EXPORT_CHUNK', 800); // pequeno para RAM baixa
        $flushEvery = (int) env('LEADS_EXPORT_FLUSH_EVERY', 2000);

        try {
            $req = new HttpRequest();
            $req->replace($this->payload);

            $columns = (array) ($this->payload['columns'] ?? []);

            // Builder original (Eloquent) com filtros/joins já aplicados
            $eloquent = LeadFilter::apply($req, $columns);

            // Chave primária e tabela p/ ordenação estável
            $table = $eloquent->getModel()->getTable();
            $pk    = $eloquent->getModel()->getKeyName() ?: 'id';
            $pkCol = "{$table}.{$pk}";

            // Query base (sem hidratar modelos) para reduzir RAM
            $base = $eloquent->toBase()->orderBy($pkCol, 'asc');

            $disk = Storage::disk($diskName);
            if (!$disk->exists($dir)) $disk->makeDirectory($dir);

            // Caminho absoluto no disco local
            $absTmp = $disk->path($tmpPath);

            // Abre stream e ajusta buffer
            $fh = @fopen($absTmp, 'wb');
            if ($fh === false) {
                throw new \RuntimeException("Falha ao abrir arquivo temporário para escrita: {$absTmp}");
            }
            // Buffer de 1MB reduz syscalls; mantém RAM controlada
            @stream_set_write_buffer($fh, 1024 * 1024);

            // BOM opcional para Excel (UTF-8)
            if ($writeBOM) {
                fwrite($fh, "\xEF\xBB\xBF");
            }

            // Cabeçalho
            fputcsv($fh, $this->headings($columns), $delimiter, $enclosure);

            // Linhas
            $written = 0;
            $base->chunk($chunkSize, function ($rows) use ($fh, $columns, $delimiter, $enclosure, $flushEvery, &$written) {
                foreach ($rows as $row) {
                    fputcsv($fh, $this->mapRecord($row, $columns), $delimiter, $enclosure);
                    $written++;
                    if ($written % $flushEvery === 0) {
                        fflush($fh);
                        if (function_exists('gc_collect_cycles')) gc_collect_cycles();
                    }
                }
                // libera lote
                unset($rows);
            });

            fflush($fh);
            fclose($fh);

            // Move p/ destino final
            $disk->move($tmpPath, $path);
            if (!$disk->exists($path)) {
                throw new \RuntimeException("Arquivo não encontrado após move");
            }

            $size = 0;
            try { $size = (int) $disk->size($path); } catch (Throwable) {}

            Cache::put($key, [
                'status'      => 'ready',
                'message'     => 'Export pronto para download.',
                'created_at'  => now()->toIso8601String(),
                'updated_at'  => now()->toIso8601String(),
                'disk'        => $diskName,
                'path'        => $path,
                'filename'    => $filename,
                'size_bytes'  => $size,
                'error'       => null,
                'ttl_seconds' => $this->ttlSeconds,
            ], $this->ttlSeconds);

            // limpeza tardia
            $grace = (int) env('LEADS_EXPORT_GRACE_SECONDS', 600);
            \App\Jobs\CleanupLeadsExportJob::dispatch($this->userId, $this->token)
                ->delay(now()->addSeconds(max(60, $this->ttlSeconds + $grace)));
        } catch (Throwable $e) {
            Log::warning("[LEADS][EXPORT] Falha token={$this->token}: ".$e->getMessage(), ['exception' => $e]);

            // Tenta fechar e remover tmp se existir
            try {
                if (isset($fh) && is_resource($fh)) fclose($fh);
                if (isset($disk, $tmpPath) && $disk->exists($tmpPath)) $disk->delete($tmpPath);
            } catch (\Throwable) {}

            Cache::put($key, [
                'status'      => 'error',
                'message'     => 'Falha ao gerar export.',
                'created_at'  => now()->toIso8601String(),
                'updated_at'  => now()->toIso8601String(),
                'disk'        => null,
                'path'        => null,
                'filename'    => null,
                'size_bytes'  => 0,
                'error'       => mb_strimwidth($e->getMessage(), 0, 1000, '…', 'UTF-8'),
                'ttl_seconds' => $this->ttlSeconds,
            ], $this->ttlSeconds);
        }
    }

    private function cacheKey(int $userId, string $token): string
    {
        return "leads_export:{$userId}:{$token}";
    }

    /* ===================== Map helpers (constante em RAM) ===================== */

    private function headings(array $columns): array
    {
        $map = [
            'id' => 'ID',
            'cpf' => 'CPF',
            'nome' => 'Nome',
            'data_nascimento' => 'Data de Nascimento',
            'fone1' => 'Telefone 1',
            'fone2' => 'Telefone 2',
            'fone3' => 'Telefone 3',
            'fone4' => 'Telefone 4',
            'classe_fone1' => 'Classe 1',
            'classe_fone2' => 'Classe 2',
            'classe_fone3' => 'Classe 3',
            'classe_fone4' => 'Classe 4',
            'consulta' => 'Motivo (Consulta)',
            'saldo' => 'Saldo',
            'libera' => 'Libera',
            'ultima_origem_cadastral' => 'Última Origem (Cadastral)',
            'ultima_origem_higienizacao' => 'Última Origem (Higienização)',
            'data_atualizacao' => 'Data de Atualização',
            'contracts_count' => 'Qtde de Contratos',
            'vendedor' => 'Vendedor',
            'data_contrato_recente' => 'Data de Contrato (mais recente)',
            'fgts_off_authorized' => 'FGTS OFF Autorizado',
            'fgts_off_consultado_em' => 'FGTS OFF Consultado em',
            'elegivel' => 'CLT Elegível',
            'idade' => 'CLT Idade',
            'sexo' => 'CLT Sexo',
            'data_admissao' => 'CLT Data de Admissão',
            'meses_admissao' => 'CLT Tempo de Casa (meses)',
            'valor_renda' => 'CLT Renda Total',
            'valor_base_margem' => 'CLT Base de Margem',
            'margem_disponivel' => 'CLT Margem Disponível',
            'valor_max_prestacao' => 'CLT Valor Máx. Prestação',
            'categoria_trabalhador_codigo' => 'CLT Categoria do Trabalhador',
            'inicio_atividade_empregador' => 'CLT Início Atividade (Empregador)',
            'qtd_emprestimos_ativos_suspensos' => 'CLT Qtde Empréstimos Ativos/Suspensos',
            'emprestimos_legados' => 'CLT Empréstimos Legados',
            'not_found' => 'CLT Não Encontrado',
            'clt_consultado_em' => 'CLT Consultado em',
        ];

        return array_map(static fn($c) => $map[$c] ?? $c, $columns);
    }

    private function mapRecord(object $lead, array $columns): array
    {
        $row = [];
        foreach ($columns as $col) {
            switch ($col) {
                case 'cpf':
                    $row[] = $this->cpfDigits($lead->cpf ?? null);
                    break;

                case 'data_atualizacao':
                case 'data_nascimento':
                case 'data_contrato_recente':
                    $row[] = $this->formatDate($lead->{$col} ?? null, in_array($col, ['data_nascimento','data_contrato_recente'], true));
                    break;

                case 'saldo':
                case 'libera':
                    $row[] = $this->toFloat($lead->{$col} ?? null);
                    break;

                case 'contracts_count':
                    $row[] = isset($lead->contracts_count) ? (int) $lead->contracts_count : null;
                    break;

                case 'fgts_off_authorized':
                    $v = $lead->fgts_off_authorized ?? null;
                    $row[] = $v === null ? null : ($v ? 'Sim' : 'Não');
                    break;

                case 'fgts_off_consultado_em':
                    $row[] = $this->formatDate($lead->fgts_off_consultado_em ?? null);
                    break;

                case 'elegivel':
                case 'not_found':
                case 'emprestimos_legados':
                    $v = $lead->{$col} ?? null;
                    $row[] = $v === null ? null : ($v ? 'Sim' : 'Não');
                    break;

                case 'data_admissao':
                case 'inicio_atividade_empregador':
                case 'clt_consultado_em':
                    $row[] = $this->formatDate($lead->{$col} ?? null, true);
                    break;

                case 'valor_renda':
                case 'valor_base_margem':
                case 'margem_disponivel':
                case 'valor_max_prestacao':
                    $row[] = $this->toFloat($lead->{$col} ?? null);
                    break;

                case 'meses_admissao':
                case 'idade':
                case 'qtd_emprestimos_ativos_suspensos':
                    $row[] = isset($lead->{$col}) ? (int) $lead->{$col} : null;
                    break;

                default:
                    $row[] = $lead->{$col} ?? null;
            }
        }
        return $row;
    }

    private function formatDate($value, bool $isDateOnly = false): ?string
    {
        if (empty($value)) return null;
        try {
            $ts = is_string($value) ? strtotime($value) : (is_int($value) ? $value : null);
            if ($ts === null) $ts = strtotime((string)$value);
            if ($ts === false) return null;
            if ($isDateOnly) {
                $d = getdate($ts);
                $ts = mktime(0, 0, 0, $d['mon'], $d['mday'], $d['year']);
            }
            return date('d/m/Y', $ts);
        } catch (\Throwable) { return null; }
    }

    private function toFloat($val): ?float
    {
        if ($val === null || $val === '') return null;
        $s = preg_replace('/[^0-9.,-]/', '', (string)$val);
        if ($s === '') return null;
        $lastDot = strrpos($s, '.'); $lastComma = strrpos($s, ',');
        if ($lastDot === false && $lastComma === false) return is_numeric($s) ? (float)$s : null;
        $dec = ($lastDot !== false && $lastComma !== false) ? (($lastDot > $lastComma) ? '.' : ',') : (($lastDot !== false) ? '.' : ',');
        $th = ($dec === '.') ? ',' : '.';
        $n = str_replace($th, '', $s);
        $n = str_replace($dec, '.', $n);
        if (substr_count($n, '.') > 1) $n = preg_replace('/\.(?=.*\.)/', '', $n);
        return is_numeric($n) ? (float)$n : null;
    }

    private function cpfDigits($val): ?string
    {
        if ($val === null || $val === '') return null;
        $d = preg_replace('/\D+/', '', (string)$val) ?? '';
        $d = ltrim($d, '0');
        if ($d === '') $d = '0';
        return $d; // string evita notação científica no Excel
    }
}
