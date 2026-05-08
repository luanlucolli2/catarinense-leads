<?php

namespace App\Modules\CLT\Jobs;

use App\Modules\CLT\Models\CltConsultJob;
use App\Modules\CLT\Support\CltLog;
use App\Modules\CLT\Support\CltSchema;
use App\Modules\CLT\Support\CltSpool;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FinalizeCltConsultReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var 'concluido'|'falhou' */
    public string $targetStatus;
    public int $timeout = 7200;

    public function __construct(public int $jobId, string $targetStatus)
    {
        $this->onQueue((string) config('cltfacta.preview.queue', 'reports'));
        $this->targetStatus = in_array($targetStatus, ['concluido', 'falhou'], true) ? $targetStatus : 'falhou';
    }

    public function handle(): void
    {
        $job = CltConsultJob::query()->whereKey($this->jobId)->first();
        if (!$job)
            return;
        if ($job->status === 'pausado') {
            return;
        }
        if ($job->status === 'cancelado') {
            if ($this->shouldPreserveCancelledSpool($job)) {
                $this->preserveCancelledSpool($job);
                $this->finishWithoutFinal($job, $job->status, false);
            } else {
                $this->finishWithoutFinal($job, $job->status);
            }
            return;
        }

        $diskName = (string) config('cltfacta.storage.reports_disk', 'local');
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        $spoolPath = $job->spool_path ?? null;
        if (!$spoolPath || !$disk->exists($spoolPath)) {
            $effectiveStatus = $this->targetStatus === 'concluido' ? 'falhou' : $this->targetStatus;
            CltLog::warning("[CLT] FINAL (job {$job->id}) spool ausente.", [
                'target_status' => $this->targetStatus,
                'effective_status' => $effectiveStatus,
                'spool_path' => $spoolPath,
                'disk' => $diskName,
            ]);
            $this->finishWithoutFinal($job, $effectiveStatus);
            return;
        }

        $previousFileDisk = $job->file_disk;
        $previousFilePath = $job->file_path;
        $path = null;
        $finalReal = null;

        try {
            $finalPrefix = (string) config('cltfacta.storage.final_prefix', 'clt-consulta');
            $dirReports = (string) config('cltfacta.storage.dir_reports', 'clt-reports');
            if (!$disk->exists($dirReports))
                $disk->makeDirectory($dirReports);

            $ts = Carbon::now()->format('Ymd_His');
            $fileName = "{$finalPrefix}_{$job->id}_{$ts}.csv";
            $path = "{$dirReports}/{$fileName}";

            // Normalização (BOM/EOL) + cabeçalho normalizado
            $embedBom = (bool) config('cltfacta.csv.embed_bom', true);
            $finalEol = strtoupper((string) config('cltfacta.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";

            $srcReal = $disk->path($spoolPath);
            $finalReal = $disk->path($path);
            $tmpReal = "{$finalReal}.tmp";

            $in = @fopen($srcReal, 'rb');
            $out = @fopen($tmpReal, 'wb');
            if ($in === false || $out === false) {
                if (is_resource($in))
                    fclose($in);
                if (is_resource($out))
                    fclose($out);
                throw new \RuntimeException("Falha ao abrir streams para promover CSV final.");
            }

            try {
                // Trata possível BOM de origem
                $peek = fread($in, 3);
                if ($peek !== "\xEF\xBB\xBF") {
                    fseek($in, 0);
                }

                // Escreve BOM final (se configurado)
                if ($embedBom) {
                    $this->writeAllOrFail($out, "\xEF\xBB\xBF", 'arquivo final CLT');
                }

                // Escreve cabeçalho normalizado
                $this->writeAllOrFail($out, CltSchema::headerCsvLine(';') . $finalEol, 'arquivo final CLT');

                // Pula a 1ª linha do arquivo de origem (cabeçalho antigo, embora já seja TITLES)
                fgets($in);

                // Copia o restante normalizando EOL
                while (!feof($in)) {
                    $chunk = fread($in, 1024 * 256);
                    if ($chunk === false)
                        break;

                    // normaliza CRLF->LF, depois LF->final
                    $chunk = str_replace("\r\n", "\n", $chunk);
                    if ($finalEol === "\r\n") {
                        $chunk = str_replace("\n", "\r\n", $chunk);
                    }
                    $this->writeAllOrFail($out, $chunk, 'arquivo final CLT');
                }
            } finally {
                fclose($in);
                try {
                    if (!fflush($out)) {
                        throw new \RuntimeException("Falha ao sincronizar CSV final em disco.");
                    }
                } finally {
                    fclose($out);
                }
            }

            // promoção local atômica (evita segunda cópia completa do arquivo)
            if (!@rename($tmpReal, $finalReal)) {
                @unlink($tmpReal);
                throw new \RuntimeException("Falha ao promover CSV final para destino.");
            }

            if (!$disk->exists($path)) {
                throw new \RuntimeException("Arquivo FINAL não encontrado após promover CSV: {$path}");
            }

            $reconciledCounts = $this->reconcileCountsFromFinalCsv($finalReal);

            $job->update(array_merge([
                'file_disk' => $diskName,
                'file_path' => $path,
                'file_name' => $fileName,
            ], $reconciledCounts));
        } catch (Throwable $e) {
            CltLog::error("[CLT] FINAL (job {$job->id}) falhou: " . $e->getMessage());
            if (is_string($path) && $path !== '' && $disk->exists($path)) {
                try {
                    $disk->delete($path);
                } catch (Throwable) {
                }
            } elseif (is_string($finalReal) && $finalReal !== '') {
                try {
                    @unlink($finalReal);
                } catch (Throwable) {
                }
            }

            $this->finishWithoutFinal($job, 'falhou', false);
            return;
        }

        $this->cleanupSpool($job);

        $job->update(['status' => $this->targetStatus, 'phase' => null, 'finished_at' => Carbon::now()]);
        if (
            is_string($previousFileDisk) && $previousFileDisk !== ''
            && is_string($previousFilePath) && $previousFilePath !== ''
            && !($previousFileDisk === $diskName && $previousFilePath === $path)
        ) {
            try {
                $previousDisk = Storage::disk($previousFileDisk);
                if ($previousDisk->exists($previousFilePath)) {
                    $previousDisk->delete($previousFilePath);
                }
            } catch (Throwable $e) {
                CltLog::warning("[CLT] FINAL (job {$job->id}) falha ao remover CSV final anterior: " . $e->getMessage());
            }
        }
        CltLog::info("[CLT] FINAL (job {$job->id}) status={$this->targetStatus} concluído.");
    }

    private function finishWithoutFinal(CltConsultJob $job, string $status, bool $cleanupSpool = true): void
    {
        if ($cleanupSpool) {
            $this->cleanupSpool($job);
        }
        $job->update(['status' => $status, 'phase' => null, 'finished_at' => Carbon::now()]);
    }

    private function cleanupSpool(CltConsultJob $job): void
    {
        try {
            $disk = Storage::disk((string) config('cltfacta.storage.reports_disk', 'local'));
            CltSpool::deleteArtifacts($disk, $job->spool_path ?? null, $job->spool_cpfs_path ?? null);
        } finally {
            $job->updateQuietly(['spool_path' => null, 'spool_cpfs_path' => null, 'spool_bytes' => 0, 'phase' => null]);
        }
    }

    private function shouldPreserveCancelledSpool(CltConsultJob $job): bool
    {
        $disk = Storage::disk((string) config('cltfacta.storage.reports_disk', 'local'));
        return CltSpool::hasDataRows($disk, $job->spool_path ?? null);
    }

    private function preserveCancelledSpool(CltConsultJob $job): void
    {
        $diskName = (string) config('cltfacta.storage.reports_disk', 'local');
        $disk = Storage::disk($diskName);
        $spoolPath = is_string($job->spool_path ?? null) ? $job->spool_path : null;
        $spoolExists = CltSpool::hasDataRows($disk, $spoolPath);
        $spoolBytes = 0;
        if ($spoolExists) {
            try {
                $spoolBytes = (int) $disk->size($spoolPath);
            } catch (Throwable) {
                $spoolBytes = 0;
            }
        }

        CltSpool::deletePhaseTwoAuxiliaryArtifacts($disk, $spoolPath, $job->spool_cpfs_path ?? null);

        $job->updateQuietly([
            'spool_path' => $spoolExists ? $spoolPath : null,
            'spool_cpfs_path' => null,
            'spool_bytes' => $spoolBytes,
            'phase' => $spoolExists ? $job->phase : null,
        ]);
    }

    private function writeAllOrFail($handle, string $data, string $context): void
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written <= 0) {
                throw new \RuntimeException("Falha ao escrever dados em {$context}.");
            }

            $offset += $written;
        }
    }

    /**
     * @return array{elegivel_count:int,inelegivel_count:int,descartado_count:int,not_found_count:int,fail_count:int,phase2_aprovado_count:int,phase2_nao_aprovado_count:int}
     */
    private function reconcileCountsFromFinalCsv(string $finalReal): array
    {
        $fh = @fopen($finalReal, 'rb');
        if (!is_resource($fh)) {
            throw new \RuntimeException("Falha ao abrir CSV final para reconciliar contadores.");
        }

        try {
            $header = fgetcsv($fh, 0, ';');
            if ($header === false) {
                throw new \RuntimeException("CSV final vazio ao reconciliar contadores.");
            }

            $cpfStates = [];
            $phase2ApprovedCount = 0;
            $phase2NotApprovedCount = 0;

            while (($csvRow = fgetcsv($fh, 0, ';')) !== false) {
                $assoc = [];
                foreach (CltSchema::COLS as $idx => $key) {
                    $assoc[$key] = $csvRow[$idx] ?? null;
                }

                $phase2Approved = $this->simNaoToBool($assoc['politicaCreditoAprovado'] ?? null);
                if ($phase2Approved === true) {
                    $phase2ApprovedCount++;
                } elseif ($phase2Approved === false) {
                    $phase2NotApprovedCount++;
                }

                $cpf = preg_replace('/\D+/', '', (string) ($assoc['cpf'] ?? ''));
                if (!is_string($cpf) || strlen($cpf) !== 11) {
                    continue;
                }

                if (!isset($cpfStates[$cpf])) {
                    $cpfStates[$cpf] = [
                        'has_eligible' => false,
                        'has_success' => false,
                        'has_discarded' => false,
                        'has_not_found' => false,
                        'has_fail' => false,
                    ];
                }

                $eligivel = $this->simNaoToBool($assoc['elegivel'] ?? null);
                $mensagem = trim((string) ($assoc['mensagem'] ?? ''));
                $politicaCreditoMensagem = trim((string) ($assoc['politicaCreditoMensagem'] ?? ''));
                $numeroVinculos = max(0, (int) preg_replace('/\D+/', '', (string) ($assoc['numeroVinculos'] ?? '0')));

                if ($this->isNotFoundCsvMessage($mensagem)) {
                    $cpfStates[$cpf]['has_not_found'] = true;
                    continue;
                }

                if ($this->isDiscardedCsvMessage($politicaCreditoMensagem)) {
                    $cpfStates[$cpf]['has_discarded'] = true;
                    continue;
                }

                if ($eligivel === true) {
                    $cpfStates[$cpf]['has_success'] = true;
                    $cpfStates[$cpf]['has_eligible'] = true;
                    continue;
                }

                if ($eligivel === false || $numeroVinculos > 0 || $this->isSuccessCsvMessage($mensagem)) {
                    $cpfStates[$cpf]['has_success'] = true;
                    continue;
                }

                $cpfStates[$cpf]['has_fail'] = true;
            }
        } finally {
            fclose($fh);
        }

        $eligibleCount = 0;
        $ineligibleCount = 0;
        $discardedCount = 0;
        $notFoundCount = 0;
        $failCount = 0;

        foreach ($cpfStates as $state) {
            if (!empty($state['has_eligible'])) {
                $eligibleCount++;
                continue;
            }

            if (!empty($state['has_not_found'])) {
                $notFoundCount++;
                continue;
            }

            if (!empty($state['has_discarded'])) {
                $discardedCount++;
                continue;
            }

            if (!empty($state['has_success'])) {
                $ineligibleCount++;
                continue;
            }

            if (!empty($state['has_fail'])) {
                $failCount++;
            }
        }

        return [
            'elegivel_count' => $eligibleCount,
            'inelegivel_count' => $ineligibleCount,
            'descartado_count' => $discardedCount,
            'not_found_count' => $notFoundCount,
            'fail_count' => $failCount,
            'phase2_aprovado_count' => $phase2ApprovedCount,
            'phase2_nao_aprovado_count' => $phase2NotApprovedCount,
        ];
    }

    private function simNaoToBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((float) $value) !== 0.0;
        }

        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'SIM', 'S', 'TRUE', '1' => true,
            'NÃO', 'NAO', 'N', 'FALSE', '0' => false,
            default => null,
        };
    }

    private function isSuccessCsvMessage(string $mensagem): bool
    {
        return $mensagem === 'Sucesso' || $mensagem === 'Sem vínculos';
    }

    private function isNotFoundCsvMessage(string $mensagem): bool
    {
        return in_array($mensagem, [
            'CPF não encontrado na base',
            'Nenhum dado encontrado!',
            'HTTP 404',
        ], true);
    }

    private function isDiscardedCsvMessage(string $mensagem): bool
    {
        return $mensagem === 'Não possui dados suficientes para validação e precisa consultar antes.';
    }
}
