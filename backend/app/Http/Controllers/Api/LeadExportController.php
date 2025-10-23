<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportLeadsRequest;
use App\Jobs\GenerateLeadsExportJob;
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

        $ttlSeconds = (int) env('LEADS_EXPORT_TTL_SECONDS', 6 * 3600);

        $payload = $request->validated();

        $key = $this->cacheKey($userId, $token);
        Cache::put($key, [
            'status'       => 'queued',
            'message'      => 'Export enfileirado.',
            'created_at'   => now()->toIso8601String(),
            'updated_at'   => now()->toIso8601String(),
            'disk'         => null,
            'path'         => null,
            'filename'     => null,
            'size_bytes'   => 0,
            'error'        => null,
            'ttl_seconds'  => $ttlSeconds,
        ], $ttlSeconds);

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
        $data   = Cache::get($this->cacheKey($userId, $token));

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

    /** Download do arquivo pronto. */
    public function download(Request $request, string $token)
    {
        $userId = (int) $request->user()->id;
        $data   = Cache::get($this->cacheKey($userId, $token));

        if (!$data) {
            return response()->json(['message' => 'Token inválido ou expirado.'], Response::HTTP_NOT_FOUND);
        }
        if (($data['status'] ?? 'none') !== 'ready') {
            return response()->json([
                'message' => 'Arquivo ainda não está pronto.',
                'status'  => $data['status'] ?? 'none',
            ], Response::HTTP_CONFLICT);
        }

        $disk = Storage::disk($data['disk'] ?: 'local');
        if (!$disk->exists($data['path'])) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_GONE);
        }

        $name = $data['filename'] ?: 'leads_export.csv';

        // Preferir o helper nativo que define o Content-Type correto por extensão
        if (method_exists($disk, 'download')) {
            return $disk->download($data['path'], $name);
        }

        $stream = $disk->readStream($data['path']);
        if ($stream === false) {
            return response()->json(['message' => 'Falha ao abrir arquivo.'], 500);
        }

        // CSV por padrão
        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) fclose($stream);
        }, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function cacheKey(int $userId, string $token): string
    {
        return "leads_export:{$userId}:{$token}";
    }
}
