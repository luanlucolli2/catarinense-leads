<?php

namespace App\Modules\Leads\Jobs;

use App\Modules\Leads\Support\LeadsExportCacheState;
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
        $key  = LeadsExportCacheState::key($this->userId, $this->token);
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
            Cache::put(
                $key,
                LeadsExportCacheState::deleted($data, $diskName, $path, 'Arquivo removido por expiração.'),
                min($ttl, $deletedStatusTtlCap)
            );
        } catch (\Throwable $e) {
            Cache::forget($key);
        }
    }
}
