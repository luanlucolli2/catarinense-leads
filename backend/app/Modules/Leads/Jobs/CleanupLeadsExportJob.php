<?php

namespace App\Modules\Leads\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupLeadsExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public int $userId, public string $token)
    {
        $this->timeout = max(1, (int) config('leads.export.cleanup_timeout_seconds', 300));
        $this->onQueue((string) config('leads.export.queue', 'reports'));
    }

    public function handle(): void
    {
        $key  = $this->cacheKey($this->userId, $this->token);
        $data = Cache::get($key);
        if (!$data) return;

        // já removido por download
        if (($data['status'] ?? null) === 'deleted') {
            Cache::forget($key);
            return;
        }

        $diskName = $data['disk'] ?: (string) config('leads.export.storage.disk', 'local');
        $path     = $data['path'] ?? null;

        if ($path) {
            try {
                /** @var FilesystemAdapter $disk */
                $disk = Storage::disk($diskName);
                if ($disk->exists($path)) $disk->delete($path);
            } catch (\Throwable $e) {
                Log::warning("[LEADS][CLEANUP] token={$this->token} erro ao deletar: ".$e->getMessage());
            }
        }

        // publica "deleted" por curto período para o poller, depois some
        try {
            $ttl   = (int) ($data['ttl_seconds'] ?? 3600);
            $deletedStatusTtlCap = max(1, (int) config('leads.export.cache.deleted_status_ttl_cap_seconds', 600));
            Cache::put($key, [
                'status'      => 'deleted',
                'message'     => 'Arquivo removido por expiração.',
                'created_at'  => $data['created_at'] ?? now()->toIso8601String(),
                'updated_at'  => now()->toIso8601String(),
                'disk'        => $diskName,
                'path'        => $path,
                'filename'    => $data['filename'] ?? null,
                'size_bytes'  => 0,
                'error'       => null,
                'ttl_seconds' => $ttl,
            ], min($ttl, $deletedStatusTtlCap));
        } catch (\Throwable $e) {
            Cache::forget($key);
        }
    }

    private function cacheKey(int $userId, string $token): string
    {
        $prefix = (string) config('leads.export.cache.key_prefix', 'leads_export');
        return "{$prefix}:{$userId}:{$token}";
    }
}
