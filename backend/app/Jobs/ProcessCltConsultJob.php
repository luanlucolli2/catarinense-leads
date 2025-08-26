<?php

namespace App\Jobs;

use App\Exports\CltConsultExport;
use App\Models\CltConsultJob;
use App\Services\FactaApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ProcessCltConsultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Timeout por job (segundos). Controlado via .env: CLT_JOB_TIMEOUT. */
    public int $timeout;

    /** Intervalo em segundos para gerar a prévia. */
    private const PREVIEW_INTERVAL_SECONDS = 60;

    /** A cada quantos CPFs checar o cancelamento (não mais usado por CPF; agora por chunk) */
    private const CANCEL_CHECK_THROTTLE = 20;

    private int $jobId;
    private int $userId;
    private string $title;

    /** @var string[] CPFs válidos (11 dígitos) */
    private array $cpfs;

    /** @var string[] CPFs inválidos (11 dígitos mas DV inválido) */
    private array $invalidCpfs;

    public function __construct(int $jobId, int $userId, string $title, array $cpfs, array $invalidCpfs = [])
    {
        $this->jobId       = $jobId;
        $this->userId      = $userId;
        $this->title       = $title;
        $this->cpfs        = array_values(array_unique($cpfs));
        $this->invalidCpfs = array_values(array_unique($invalidCpfs));

        $this->onQueue('default');
        $this->timeout = (int) env('CLT_JOB_TIMEOUT', 18000); // padrão 3h
    }

    public function handle(FactaApiService $facta): void
    {
        /** @var CltConsultJob $job */
        $job = CltConsultJob::query()->whereKey($this->jobId)->firstOrFail();

        if ($this->isCancelled()) {
            Log::info("[CLT] Job {$this->jobId} já cancelado antes do início.");
            $this->deletePreview($job);
            return;
        }

        if (!$this->isCancelled()) {
            $job->update([
                'status'     => 'em_progresso',
                'started_at' => Carbon::now(),
                'total_cpfs' => count($this->cpfs) + count($this->invalidCpfs),
            ]);
        } else {
            Log::info("[CLT] Job {$this->jobId} cancelado na largada.");
            $this->deletePreview($job);
            return;
        }

        $maxAttempts = (int) env('CLT_CONSULT_MAX_ATTEMPTS', 5);
        $retryDelay  = (int) env('CLT_CONSULT_RETRY_DELAY_SECONDS', 60);
        $chunkSize   = (int) env('CLT_HTTP_CHUNK', 20); // <<< NOVO: tamanho do lote concorrente

        $rows             = [];
        $successMap       = [];
        $lastError        = [];
        $terminalFailures = [];
        $pendentes        = $this->cpfs;
        $invalidCount     = count($this->invalidCpfs);

        $lastPreviewTime  = Carbon::now();

        try {
            // 1) CPFs inválidos (linha direta)
            foreach ($this->invalidCpfs as $cpfInv) {
                $row = $this->baseRow($cpfInv);
                $row['numeroVinculos'] = 0;
                $row['mensagem'] = 'CPF inválido (dígitos verificadores)';
                $rows[] = $row;
            }

            // 2) Válidos com teimosinha em LOTES concorrentes
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($this->finishIfCancelled($job)) return;
                if (empty($pendentes)) break;

                Log::info("[CLT] Job {$this->jobId} tentativa {$attempt} – pendentes: ".count($pendentes));

                $toTry = $pendentes;
                $chunks = array_chunk($toTry, max(1, $chunkSize));

                foreach ($chunks as $idx => $chunkCpfs) {
                    if ($this->finishIfCancelled($job)) return;

                    // Dispara o LOTE
                    $batchResults = $facta->autorizaConsultaLote($chunkCpfs);

                    // Consolida resultados do lote
                    $successInChunk = 0;

                    foreach ($chunkCpfs as $cpf) {
                        $res = $batchResults[$cpf] ?? [
                            'ok' => false,
                            'mensagem' => 'Sem resposta do serviço',
                            'vinculos' => null,
                            'retriable' => true,
                            'not_found' => false,
                        ];

                        if ($res['ok'] === true) {
                            $vinculos = $res['vinculos'] ?? [];
                            $total = is_array($vinculos) ? count($vinculos) : 0;

                            if ($total > 0) {
                                foreach ($vinculos as $v) {
                                    $row = $this->baseRow($cpf);
                                    $row['numeroVinculos'] = $total;

                                    // Núcleo
                                    $row['elegivel']                  = $v['elegivel']                      ?? null;
                                    $row['valorMargemDisponivel']     = $v['valorMargemDisponivel']         ?? null;
                                    $row['valorMaximoPrestacao']      = $this->computeValorMaximoPrestacao($v['valorMargemDisponivel'] ?? null);
                                    $row['valorBaseMargem']           = $v['valorBaseMargem']               ?? null;
                                    $row['valorTotalVencimentos']     = $v['valorTotalVencimentos']         ?? null;

                                    // Vínculo/empregador
                                    $row['nomeEmpregador']            = $v['nomeEmpregador']                ?? null;
                                    $row['numeroInscricaoEmpregador'] = $v['numeroInscricaoEmpregador']     ?? null;
                                    $row['inscricaoEmpregador_descricao'] = $v['inscricaoEmpregador_descricao'] ?? null;
                                    $row['matricula']                 = $v['matricula']                     ?? null;
                                    $row['dataAdmissao']              = $v['dataAdmissao']                  ?? null;
                                    $row['tempoAdmissaoMeses']        = $this->computeTempoAdmissaoMeses($v['dataAdmissao'] ?? null, $v['dataDesligamento'] ?? null);
                                    $row['dataDesligamento']          = $v['dataDesligamento']              ?? null;
                                    $row['codigoMotivoDesligamento']  = $v['codigoMotivoDesligamento']      ?? null;

                                    // Contexto
                                    $row['codigoCategoriaTrabalhador']= $v['codigoCategoriaTrabalhador']    ?? null;
                                    $row['cbo_descricao']             = $v['cbo_descricao']                 ?? null;
                                    $row['cnae_descricao']            = $v['cnae_descricao']                ?? null;
                                    $row['dataInicioAtividadeEmpregador'] = $v['dataInicioAtividadeEmpregador'] ?? null;

                                    // Alertas
                                    $row['possuiAlertas']             = $v['possuiAlertas']                 ?? null;
                                    $row['qtdEmprestimosAtivosSuspensos'] = $v['qtdEmprestimosAtivosSuspensos'] ?? null;
                                    $row['emprestimosLegados']        = $v['emprestimosLegados']            ?? null;
                                    $row['pessoaExpostaPoliticamente_descricao'] = $v['pessoaExpostaPoliticamente_descricao'] ?? null;

                                    // Identificação
                                    $row['nome']                      = $v['nome']                          ?? null;
                                    $row['dataNascimento']            = $v['dataNascimento']                ?? null;
                                    $row['idade']                     = $this->computeIdadeAnos($v['dataNascimento'] ?? null);
                                    $row['sexo_descricao']            = $v['sexo_descricao']                ?? null;

                                    // Meta/status
                                    $row['status_code']               = $v['status_code']                   ?? null;
                                    $row['mensagem']                  = $res['mensagem']                    ?? 'OK';

                                    $rows[] = $row;
                                }
                            } else {
                                $row = $this->baseRow($cpf);
                                $row['numeroVinculos'] = 0;
                                $row['mensagem'] = $res['mensagem'] ?? 'Sem vínculos';
                                $rows[] = $row;
                            }

                            $successMap[$cpf] = true;
                            $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                            $successInChunk++;

                        } else {
                            $msg = (string) ($res['mensagem'] ?? 'Falha na consulta');

                            // Caso neutro "CPF não encontrado na base"
                            if (!empty($res['not_found'])) {
                                $row = $this->baseRow($cpf);
                                $row['numeroVinculos'] = 0;
                                $row['mensagem'] = $msg; // mantém mensagem original
                                $rows[] = $row;

                                $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                                continue;
                            }

                            if (isset($res['retriable']) && $res['retriable'] === false) {
                                $terminalFailures[$cpf] = $msg;
                                $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                            } else {
                                $lastError[$cpf] = $msg;
                            }
                        }
                    }

                    // Incrementa successes de uma vez (menos I/O no DB)
                    if ($successInChunk > 0) {
                        $job->increment('success_count', $successInChunk);
                    }

                    // prévia por tempo (pós-chunk)
                    if ($lastPreviewTime->diffInSeconds(Carbon::now()) >= self::PREVIEW_INTERVAL_SECONDS) {
                        if ($this->finishIfCancelled($job)) return;
                        Log::info("[CLT] Job {$this->jobId} gerando prévia por tempo (chunk #".($idx+1).").");
                        $this->generatePreview($job, $rows, $pendentes, $terminalFailures);
                        $lastPreviewTime = Carbon::now();
                    }
                }

                if ($this->finishIfCancelled($job)) return;

                // prévia no fim da tentativa (garante atualização mesmo em loops rápidos)
                Log::info("[CLT] Job {$this->jobId} gerando prévia ao final da tentativa {$attempt}.");
                $this->generatePreview($job, $rows, $pendentes, $terminalFailures);
                $lastPreviewTime = Carbon::now();

                if (!empty($pendentes) && $attempt < $maxAttempts) {
                    if ($this->finishIfCancelled($job)) return;
                    sleep(max(1, $retryDelay));
                }
            }

            // 3) Falhas não-retriáveis
            foreach ($terminalFailures as $cpf => $msg) {
                if ($this->finishIfCancelled($job)) return;
                $row = $this->baseRow($cpf);
                $row['numeroVinculos'] = 0;
                $row['mensagem'] = $msg;
                $rows[] = $row;
            }

            // 4) Falhas após teimosinha
            foreach ($pendentes as $cpf) {
                if ($this->finishIfCancelled($job)) return;
                $row = $this->baseRow($cpf);
                $row['numeroVinculos'] = 0;
                $row['mensagem'] = $lastError[$cpf] ?? 'Não foi possível consultar após múltiplas tentativas';
                $rows[] = $row;
            }

            if ($this->isCancelled()) {
                $job->update(['finished_at' => Carbon::now()]);
                $this->deletePreview($job);
                Log::info("[CLT] Job {$this->jobId} cancelado na finalização.");
                return;
            }

            $successCount = count($successMap);
            $failCount    = $invalidCount + count($terminalFailures) + count($pendentes);

            // Excel FINAL
            $disk     = env('CLT_REPORTS_DISK', 'public');
            $dir      = 'clt-reports';
            $ts       = Carbon::now()->format('Ymd_His');
            $fileName = "clt-consulta_{$this->jobId}_{$ts}.xlsx";
            $path     = "{$dir}/{$fileName}";

            Excel::store(new CltConsultExport($rows), $path, $disk);

            if (!$this->isCancelled()) {
                $job->update([
                    'success_count' => $successCount,
                    'fail_count'    => $failCount,
                    'file_disk'     => $disk,
                    'file_path'     => $path,
                    'file_name'     => $fileName,
                    'status'        => 'concluido',
                    'finished_at'   => Carbon::now(),
                ]);
                $this->deletePreview($job);
                Log::info("[CLT] Job {$this->jobId} concluído – sucesso: {$successCount}, falha: {$failCount}");
            } else {
                $job->update(['finished_at' => Carbon::now()]);
                $this->deletePreview($job);
                Log::info("[CLT] Job {$this->jobId} cancelado após geração de artefatos.");
            }

        } catch (Throwable $e) {
            Log::error("[CLT] Job {$this->jobId} falhou: ".$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $job->update([
                'status'      => 'falhou',
                'finished_at' => Carbon::now(),
            ]);
            $this->deletePreview($job);
        }
    }

    /** ----------------------- Helpers ----------------------- */

    private function isCancelled(): bool
    {
        $status = DB::table('clt_consult_jobs')->where('id', $this->jobId)->value('status');
        return $status === 'cancelado';
    }

    private function finishIfCancelled(CltConsultJob $job): bool
    {
        if ($this->isCancelled()) {
            $job->update(['finished_at' => Carbon::now()]);
            $this->deletePreview($job);
            Log::info("[CLT] Job {$this->jobId} interrompido por cancelamento.");
            return true;
        }
        return false;
    }

    private function baseRow(string $cpf): array
    {
        $row = [];
        foreach (\App\Exports\CltConsultExport::COLS as $col) {
            $row[$col] = null;
        }
        $row['cpf'] = $cpf;
        return $row;
    }

    /**
     * Monta e salva a PRÉVIA no disco configurado.
     */
    private function generatePreview(CltConsultJob $job, array $rows, array $pendentes, array $terminalFailures): void
    {
        try {
            $rowsPreview = $rows;

            foreach ($terminalFailures as $cpf => $msg) {
                $rowsPreview[] = array_merge($this->baseRow($cpf), [
                    'numeroVinculos' => 0,
                    'mensagem'       => $msg,
                ]);
            }

            foreach ($pendentes as $cpf) {
                $rowsPreview[] = array_merge($this->baseRow($cpf), [
                    'numeroVinculos' => 0,
                    'mensagem'       => 'Em andamento',
                ]);
            }

            $disk        = env('CLT_REPORTS_DISK', 'public');
            $dir         = 'clt-previews';
            $fileName    = "clt-consulta_{$this->jobId}_preview.xlsx";
            $path        = "{$dir}/{$fileName}";
            $updatedAt   = Carbon::now();

            Excel::store(new CltConsultExport($rowsPreview), $path, $disk);

            $job->update([
                'preview_disk'       => $disk,
                'preview_path'       => $path,
                'preview_name'       => $fileName,
                'preview_updated_at' => $updatedAt,
            ]);
        } catch (Throwable $e) {
            Log::warning("[CLT] Job {$this->jobId} falha ao gerar prévia: ".$e->getMessage());
        }
    }

    /** Apaga a prévia (arquivo+campos) se existir */
    private function deletePreview(CltConsultJob $job): void
    {
        try {
            if ($job->preview_disk && $job->preview_path) {
                $disk = Storage::disk($job->preview_disk);
                if ($disk->exists($job->preview_path)) {
                    $disk->delete($job->preview_path);
                }
            }
        } catch (Throwable $e) {
            Log::warning("[CLT] Job {$this->jobId} falha ao apagar prévia: ".$e->getMessage());
        } finally {
            $job->updateQuietly([
                'preview_disk'       => null,
                'preview_path'       => null,
                'preview_name'       => null,
                'preview_updated_at' => null,
            ]);
        }
    }

    private function computeValorMaximoPrestacao($valorMargemDisponivel): ?string
    {
        $f = $this->toFloatPtBr($valorMargemDisponivel);
        if ($f === null) return null;
        $calc = $f * 0.70;
        return $this->formatPtBrMoney($calc);
    }

    private function computeIdadeAnos(?string $dataNascimento): ?int
    {
        $d = $this->parseDateBr($dataNascimento);
        return $d ? $d->diffInYears(Carbon::now()) : null;
    }

    private function computeTempoAdmissaoMeses(?string $dataAdmissao, ?string $dataDesligamento): ?int
    {
        $ini = $this->parseDateBr($dataAdmissao);
        if (!$ini) return null;
        $fim = $this->parseDateBr($dataDesligamento) ?? Carbon::now();
        if ($fim->lt($ini)) return 0;
        return $ini->diffInMonths($fim);
    }

    private function parseDateBr(?string $s): ?Carbon
    {
        if (!$s) return null;
        try {
            return \Illuminate\Support\Carbon::createFromFormat('d/m/Y', trim($s))->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function toFloatPtBr($v): ?float
    {
        if ($v === null) return null;
        if (is_numeric($v)) return (float) $v;
        $s = preg_replace('/[^\d,\-\.]/', '', (string) $v);
        if ($s === '' || $s === '-') return null;
        $s = str_replace(['.', ','], ['', '.'], $s);
        if (!is_numeric($s)) return null;
        return (float) $s;
    }

    private function formatPtBrMoney(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }
}
