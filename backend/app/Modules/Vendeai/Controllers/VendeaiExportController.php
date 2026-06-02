<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Jobs\GenerateVendeaiExportJob;
use App\Modules\Vendeai\Support\VendeaiCsvExport;
use App\Modules\Vendeai\Support\VendeaiExportCacheState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class VendeaiExportController extends Controller
{
    public function leads(Request $request): Response
    {
        return $this->startExport($request, VendeaiCsvExport::TYPE_LEADS);
    }

    public function newCorbanProposalAttempts(Request $request): Response
    {
        return $this->startExport($request, VendeaiCsvExport::TYPE_ATTEMPTS);
    }

    public function status(Request $request, string $token): Response
    {
        $data = Cache::get(VendeaiExportCacheState::key((int) $request->user()->id, $token));

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
        $cacheKey = VendeaiExportCacheState::key((int) $request->user()->id, $token);
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

        $diskName = $data['disk'] ?: (string) config('vendeai.export.storage.disk', 'local');
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

        return response()->streamDownload(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, $data['filename'] ?: (string) config('vendeai.export.storage.fallback_filename', 'vendeai_export.csv'), [
            'Content-Type' => (string) config('vendeai.export.stream.content_type', 'text/csv; charset=UTF-8'),
            'X-Accel-Buffering' => (string) config('vendeai.export.stream.accel_buffering', 'no'),
        ]);
    }

    private function startExport(Request $request, string $type): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['all', 'success', 'failed', 'pending'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'newcorban_filter' => ['nullable', Rule::in(['all', 'sent', 'created'])],
        ]);

        if ($type === VendeaiCsvExport::TYPE_LEADS) {
            unset($validated['status']);
        } else {
            unset($validated['newcorban_filter']);
        }

        $token = (string) Str::uuid();
        $userId = (int) $request->user()->id;
        $ttlSeconds = max(60, (int) config('vendeai.export.ttl_seconds', 6 * 3600));
        $diskName = (string) config('vendeai.export.storage.disk', 'local');
        $dir = trim((string) config('vendeai.export.storage.directory', 'vendeai-exports'), '/');

        try {
            $disk = Storage::disk($diskName);
            if (! $disk->exists($dir)) {
                $disk->makeDirectory($dir);
            }
        } catch (\Throwable) {
        }

        Cache::put(
            VendeaiExportCacheState::key($userId, $token),
            VendeaiExportCacheState::queued($ttlSeconds),
            $ttlSeconds
        );

        GenerateVendeaiExportJob::dispatch($userId, $token, $type, $validated, $ttlSeconds);

        return response()->json([
            'token' => $token,
            'status' => 'queued',
        ], Response::HTTP_ACCEPTED);
    }
}
