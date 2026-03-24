<?php

declare(strict_types=1);

namespace App\Modules\Uy3\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Uy3\Jobs\GenerateUy3ExportJob;
use App\Modules\Uy3\Requests\ExportUy3PostsRequest;
use App\Modules\Uy3\Support\Uy3ExportCacheState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class Uy3PostExportController extends Controller
{
    public function export(ExportUy3PostsRequest $request): Response
    {
        $token = (string) Str::uuid();
        $userId = (int) $request->user()->id;
        $ttlSeconds = max(60, (int) config('uy3.export.ttl_seconds', 6 * 3600));
        $payload = $request->validated();
        $diskName = (string) config('uy3.export.storage.disk', 'local');
        $dir = trim((string) config('uy3.export.storage.directory', 'uy3-exports'), '/');

        // Garante a pasta no contexto do processo web (mesmo usuário do download).
        try {
            $disk = Storage::disk($diskName);
            if (! $disk->exists($dir)) {
                $disk->makeDirectory($dir);
            }
        } catch (\Throwable) {
        }

        $key = Uy3ExportCacheState::key($userId, $token);
        Cache::put($key, Uy3ExportCacheState::queued($ttlSeconds), $ttlSeconds);

        GenerateUy3ExportJob::dispatch($userId, $token, $payload, $ttlSeconds);

        return response()->json([
            'token' => $token,
            'status' => 'queued',
        ], Response::HTTP_ACCEPTED);
    }

    public function status(Request $request, string $token): Response
    {
        $userId = (int) $request->user()->id;
        $data = Cache::get(Uy3ExportCacheState::key($userId, $token));

        if (! $data) {
            return response()->json(['message' => 'Token inválido ou expirado.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'status' => $data['status'] ?? 'none',
            'message' => $data['message'] ?? null,
            'filename' => $data['filename'] ?? null,
            'size_bytes' => (int) ($data['size_bytes'] ?? 0),
            'updated_at' => $data['updated_at'] ?? null,
            'error' => $data['error'] ?? null,
        ]);
    }

    public function download(Request $request, string $token): Response
    {
        $userId = (int) $request->user()->id;
        $cacheKey = Uy3ExportCacheState::key($userId, $token);
        $data = Cache::get($cacheKey);

        if (! $data) {
            return response()->json(['message' => 'Token inválido ou expirado.'], Response::HTTP_NOT_FOUND);
        }

        if (($data['status'] ?? 'none') !== 'ready') {
            return response()->json([
                'message' => 'Arquivo ainda não está pronto.',
                'status' => $data['status'] ?? 'none',
            ], Response::HTTP_CONFLICT);
        }

        $diskName = $data['disk'] ?: (string) config('uy3.export.storage.disk', 'local');
        $path = $data['path'] ?? null;
        if (! $path) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_GONE);
        }

        $disk = Storage::disk($diskName);
        if (! $disk->exists($path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_GONE);
        }

        $stream = $disk->readStream($path);
        if ($stream === false) {
            return response()->json(['message' => 'Falha ao abrir arquivo.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $headers = [
            'Content-Type' => (string) config('uy3.export.stream.content_type', 'text/csv; charset=UTF-8'),
            'X-Accel-Buffering' => (string) config('uy3.export.stream.accel_buffering', 'no'),
        ];
        $name = $data['filename'] ?: (string) config('uy3.export.storage.fallback_filename', 'uy3_export.csv');

        return response()->streamDownload(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, $name, $headers);
    }
}
