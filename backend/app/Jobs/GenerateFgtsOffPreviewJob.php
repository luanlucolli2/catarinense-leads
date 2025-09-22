<?php

namespace App\Jobs;

use App\Exports\FgtsOfflineExport;
use App\Models\FgtsOfflineJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class GenerateFgtsOffPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Timeout (segundos) — gerar XLSX pode ser pesado, mas previsível */
    public int $timeout = 7200;

    public function __construct(public int $consultJobId)
    {
        // Fila dedicada a relatórios/prévias
        $this->onQueue((string) config('facta_off.preview.queue', 'reports'));
    }

    public function handle(): void
    {
        /** @var FgtsOfflineJob|null $job */
        $job = FgtsOfflineJob::query()->whereKey($this->consultJobId)->first();

        if (!$job) {
            return;
        }

        // Se o job foi cancelado/excluído, não faz sentido gerar prévia
        $statusNow = DB::table('fgts_off_consult_jobs')->where('id', $job->id)->value('status');
        if (in_array($statusNow, ['cancelado'], true)) {
            $this->markNone($job);
            return;
        }

        $diskName = (string) config('facta_off.storage.reports_disk', 'public');
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        if (
            empty($job->spool_path) || empty($job->spool_cpfs_path) ||
            !$disk->exists($job->spool_path) || !$disk->exists($job->spool_cpfs_path)
        ) {
            $this->markError($job, 'Prévia indisponível: spool ausente.');
            return;
        }

        // Captura o spool_bytes atual para atualização condicional ao final
        $spoolBytesAtStart = (int) ($job->spool_bytes ?? 0);

        // Sinaliza execução
        $job->update([
            'preview_status' => 'running',
            'preview_started_at' => Carbon::now(),
            'preview_error' => null,
        ]);

        try {
            $finalPrefix = (string) config('facta_off.storage.final_prefix', 'fgts-offline');
            $dirPreviews = (string) config('facta_off.storage.dir_previews', 'fgts-off-previews');

            // garante diretório de prévias (primeira execução/ambiente novo)
            if (!$disk->exists($dirPreviews)) {
                $disk->makeDirectory($dirPreviews);
            }

            $fileName = $job->preview_name ?: "{$finalPrefix}_{$job->id}_preview.xlsx";
            $tmpName = preg_replace('/\.xlsx$/', '.tmp.xlsx', $fileName);

            $path = "{$dirPreviews}/{$fileName}";
            $tmpPath = "{$dirPreviews}/{$tmpName}";

            $spoolReal = $disk->path($job->spool_path);
            $cpfsReal = $disk->path($job->spool_cpfs_path);

            $processedCount = 0;
            $pendingCount = 0;

            $iteratorFactory = function () use ($spoolReal, $cpfsReal, &$processedCount, &$pendingCount): \Generator {
                $done = [];

                $fh = fopen($spoolReal, 'r');
                if ($fh !== false) {
                    try {
                        flock($fh, LOCK_SH);
                        fgetcsv($fh, 0, ';'); // cabeçalho
                        while (($data = fgetcsv($fh, 0, ';')) !== false) {
                            $assoc = [];
                            foreach (\App\Exports\FgtsOfflineExport::COLS as $i => $key) {
                                $assoc[$key] = $data[$i] ?? null;
                            }
                            $cpf = (string) ($assoc['cpf'] ?? '');
                            if ($cpf !== '')
                                $done[$cpf] = true;
                            $processedCount++;
                            yield $assoc;
                        }
                    } finally {
                        flock($fh, LOCK_UN);
                        fclose($fh);
                    }
                }

                $fh2 = fopen($cpfsReal, 'r');
                if ($fh2 !== false) {
                    try {
                        flock($fh2, LOCK_SH);
                        while (($line = fgets($fh2)) !== false) {
                            $cpf = trim($line);
                            if ($cpf === '' || isset($done[$cpf]))
                                continue;

                            $row = array_fill_keys(\App\Exports\FgtsOfflineExport::COLS, null);
                            $row['cpf'] = $cpf;
                            $row['mensagem'] = 'Em andamento';
                            $row['consultadoEm'] = Carbon::now('America/Sao_Paulo')->format('d/m/Y H:i:s');
                            $pendingCount++;
                            yield $row;
                        }
                    } finally {
                        flock($fh2, LOCK_UN);
                        fclose($fh2);
                    }
                }
            };

            $export = FgtsOfflineExport::fromGenerator($iteratorFactory);
            Excel::store($export, $tmpPath, $diskName);
            $disk->move($tmpPath, $path);

            if (!$disk->exists($path)) {
                throw new \RuntimeException("Prévia não encontrada após move: {$path}");
            }

            // Cancelado durante a geração?
            $statusAfter = DB::table('fgts_off_consult_jobs')->where('id', $job->id)->value('status');
            if ($statusAfter === 'cancelado') {
                try {
                    $disk->delete($path);
                } catch (Throwable) {
                }
                $this->markNone($job);
                return;
            }

            $sizeBytes = 0;
            try {
                $sizeBytes = (int) $disk->size($path);
            } catch (Throwable) {
            }

            // Atualiza campos da prévia (sem tocar em preview_dirty aqui)
            $job->update([
                'preview_disk' => $diskName,
                'preview_path' => $path,
                'preview_name' => $fileName,
                'preview_updated_at' => Carbon::now(),
                'preview_status' => 'ready',
                'preview_finished_at' => Carbon::now(),
                'preview_size_bytes' => $sizeBytes,
                'preview_rows' => ($processedCount + $pendingCount),
                'preview_error' => null,
            ]);

            // ✅ Só limpamos o preview_dirty se o spool NÃO mudou desde o início
            DB::table('fgts_off_consult_jobs')
                ->where('id', $job->id)
                ->where('spool_bytes', $spoolBytesAtStart)
                ->update([
                    'preview_dirty' => false,
                    'updated_at' => Carbon::now(),
                ]);

        } catch (Throwable $e) {
            Log::warning("[FGTS-OFF] Prévia (job {$job->id}) falhou: " . $e->getMessage());
            $this->markError($job, $e->getMessage());
        }
    }

    private function markNone(FgtsOfflineJob $job): void
    {
        $job->update([
            'preview_status' => 'none',
            'preview_requested_at' => null,
            'preview_started_at' => null,
            'preview_finished_at' => null,
            'preview_size_bytes' => 0,
            'preview_rows' => 0,
            'preview_error' => null,
        ]);
    }

    private function markError(FgtsOfflineJob $job, string $message): void
    {
        $job->update([
            'preview_status' => 'error',
            'preview_finished_at' => Carbon::now(),
            'preview_error' => mb_strimwidth($message, 0, 1000, '…', 'UTF-8'),
        ]);
    }
}
