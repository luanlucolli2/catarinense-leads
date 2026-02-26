<?php

namespace App\Modules\Leads\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Requests\ExportLeadsRequest;
use App\Modules\Leads\Jobs\GenerateLeadsExportJob;
use App\Modules\Leads\Support\LeadsExportCacheState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LeadExportController extends Controller
{
    /** Inicia geração assíncrona e retorna token. */
    public function export(ExportLeadsRequest $request)
    {
        $token  = (string) Str::uuid();
        $userId = (int) $request->user()->id;

        $ttlSeconds = max(60, (int) config('leads.export.ttl_seconds', 6 * 3600));

        $payload = $request->validated();

        $key = LeadsExportCacheState::key($userId, $token);
        Cache::put($key, LeadsExportCacheState::queued($ttlSeconds), $ttlSeconds);

        GenerateLeadsExportJob::dispatch($userId, $token, $payload, $ttlSeconds);

        return response()->json([
            'token'  => $token,
            'status' => 'queued',
        ], Response::HTTP_ACCEPTED);
    }

    /** Consulta status pelo token. */
    public function status(Request $request, string $token)
    {
        $userId = (int) $request->user()->id;
        $data   = Cache::get(LeadsExportCacheState::key($userId, $token));

        if (!$data) {
            return response()->json(['message' => 'Token inválido ou expirado.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'status'     => $data['status'],
            'message'    => $data['message'],
            'filename'   => $data['filename'],
            'size_bytes' => $data['size_bytes'],
            'updated_at' => $data['updated_at'],
        ]);
    }

    /** Download do arquivo pronto + deleção imediata e status=deleted. */
    public function download(Request $request, string $token)
    {
        $userId   = (int) $request->user()->id;
        $cacheKey = LeadsExportCacheState::key($userId, $token);
        $data     = Cache::get($cacheKey);

        if (!$data) {
            return response()->json(['message' => 'Token inválido ou expirado.'], Response::HTTP_NOT_FOUND);
        }
        if (($data['status'] ?? 'none') !== 'ready') {
            return response()->json([
                'message' => 'Arquivo ainda não está pronto.',
                'status'  => $data['status'] ?? 'none',
            ], Response::HTTP_CONFLICT);
        }

        $diskName = $data['disk'] ?: (string) config('leads.export.storage.disk', 'local');
        $path     = $data['path'] ?? null;
        if (!$path) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_GONE);
        }

        $disk = Storage::disk($diskName);
        if (!$disk->exists($path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_GONE);
        }

        $name = $data['filename'] ?: (string) config('leads.export.storage.fallback_filename', 'leads_export.csv');

        // stream manual para poder deletar e atualizar o cache após o envio
        $stream = $disk->readStream($path);
        if ($stream === false) {
            return response()->json(['message' => 'Falha ao abrir arquivo.'], 500);
        }

        $deletedStatusTtlCap = max(1, (int) config('leads.export.cache.deleted_status_ttl_cap_seconds', 600));
        $headers = [
            'Content-Type'        => (string) config('leads.export.stream.content_type', 'text/csv; charset=UTF-8'),
            'X-Accel-Buffering'   => (string) config('leads.export.stream.accel_buffering', 'no'),
        ];

        return response()->streamDownload(function () use ($stream, $diskName, $path, $cacheKey, $data, $deletedStatusTtlCap) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                try {
                    // apaga o arquivo
                    $d = Storage::disk($diskName);
                    if ($d->exists($path)) {
                        $d->delete($path);
                    }
                } catch (\Throwable $e) {
                    // log opcional
                }
                // publica status=deleted para o poller
                try {
                    $ttl = (int) ($data['ttl_seconds'] ?? 3600);
                    $cache = LeadsExportCacheState::deleted(
                        $data,
                        $diskName,
                        $path,
                        'Arquivo removido após download.'
                    );
                    Cache::put($cacheKey, $cache, min($ttl, $deletedStatusTtlCap));
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }, $name, $headers);
    }
}
