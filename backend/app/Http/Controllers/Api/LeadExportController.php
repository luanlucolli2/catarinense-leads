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
    public function export(ExportLeadsRequest $request)
    {
        $token  = (string) Str::uuid();
        $userId = (int) $request->user()->id;
        $ttl    = (int) env('LEADS_EXPORT_TTL_SECONDS', 6 * 3600);

        $payload = $request->validated();

        Cache::put($this->cacheKey($userId, $token), [
            'status'       => 'queued',
            'message'      => 'Export enfileirado.',
            'created_at'   => now()->toIso8601String(),
            'updated_at'   => now()->toIso8601String(),
            'disk'         => null,
            'path'         => null,
            'filename'     => null,
            'size_bytes'   => 0,
            'error'        => null,
            'ttl_seconds'  => $ttl,
        ], $ttl);

        GenerateLeadsExportJob::dispatch($userId, $token, $payload, $ttl);

        return response()->json(['token' => $token, 'status' => 'queued'], Response::HTTP_ACCEPTED);
    }

    public function status(Request $request, string $token)
    {
        $userId = (int) $request->user()->id;
        $data = Cache::get($this->cacheKey($userId, $token));
        if (!$data) return response()->json(['message' => 'Token inválido ou expirado.'], 404);

        return response()->json([
            'status'     => $data['status'],
            'message'    => $data['message'],
            'filename'   => $data['filename'],
            'size_bytes' => $data['size_bytes'],
            'updated_at' => $data['updated_at'],
        ]);
    }

    /** Stream + delete-at-end + marca como "deleted". */
    public function download(Request $request, string $token)
    {
        $userId = (int) $request->user()->id;
        $key = $this->cacheKey($userId, $token);
        $data = Cache::get($key);

        if (!$data) {
            return response()->json(['message' => 'Token inválido ou expirado.'], 404);
        }
        if (($data['status'] ?? 'none') !== 'ready') {
            return response()->json([
                'message' => 'Arquivo ainda não está pronto.',
                'status'  => $data['status'] ?? 'none',
            ], 409);
        }

        $disk = Storage::disk($data['disk'] ?: 'local');
        $path = $data['path'];
        if (!$path || !$disk->exists($path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 410);
        }

        $name = $data['filename'] ?: 'leads_export.xlsx';
        $stream = $disk->readStream($path);
        if ($stream === false) {
            return response()->json(['message' => 'Falha ao abrir arquivo.'], 500);
        }

        return response()->streamDownload(function () use ($disk, $path, $stream, $key, $data) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) fclose($stream);
                // tenta apagar o arquivo após envio
                try { $disk->delete($path); } catch (\Throwable $e) {}
                // marca como deletado; mantém metadados mínimos até expirar o TTL
                Cache::put($key, array_merge($data, [
                    'status'     => 'deleted',
                    'message'    => 'Arquivo removido após download.',
                    'updated_at' => now()->toIso8601String(),
                    'path'       => null,
                    'size_bytes' => 0,
                ]), (int) ($data['ttl_seconds'] ?? 3600));
            }
        }, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function cacheKey(int $userId, string $token): string
    {
        return "leads_export:{$userId}:{$token}";
    }
}
