<?php

namespace App\Modules\Vendeai\Jobs;

use App\Modules\Vendeai\Support\VendeaiExportCacheState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupVendeaiExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public int $userId, public string $token)
    {
        $this->timeout = max(1, (int) config('vendeai.export.cleanup_timeout_seconds', 300));
        $this->onQueue((string) config('vendeai.export.queue', 'reports'));
    }

    public function handle(): void
    {
        $key = VendeaiExportCacheState::key($this->userId, $this->token);
        $data = Cache::get($key);
        if (! $data) {
            return;
        }

        if (($data['status'] ?? null) === 'deleted') {
            Cache::forget($key);
            return;
        }

        $diskName = $data['disk'] ?: (string) config('vendeai.export.storage.disk', 'local');
        $path = $data['path'] ?? null;

        if ($path) {
            try {
                /** @var FilesystemAdapter $disk */
                $disk = Storage::disk($diskName);
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (\Throwable $e) {
                Log::warning("[VENDEAI][EXPORT][CLEANUP] token={$this->token} erro ao deletar: " . $e->getMessage());
            }
        }

        $ttl = (int) ($data['ttl_seconds'] ?? 3600);
        $deletedStatusTtlCap = max(1, (int) config('vendeai.export.cache.deleted_status_ttl_cap_seconds', 600));

        Cache::put(
            $key,
            VendeaiExportCacheState::deleted($data, $diskName, $path, 'Arquivo removido por expiração.'),
            min($ttl, $deletedStatusTtlCap)
        );
    }
}
