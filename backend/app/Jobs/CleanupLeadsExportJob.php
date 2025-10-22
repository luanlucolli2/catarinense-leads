<?php

namespace App\Jobs;

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
        $this->onQueue((string) env('PREVIEW_JOB_QUEUE', 'reports'));
    }

    public function handle(): void
    {
        $key  = $this->cacheKey($this->userId, $this->token);
        $data = Cache::get($key);
        if (!$data) return;

        // se já foi deletado no download, nada a fazer
        if (($data['status'] ?? null) === 'deleted') {
            Cache::forget($key);
            return;
        }

        $diskName = $data['disk'] ?: 'local';
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

        Cache::forget($key); // remove status residual
    }

    private function cacheKey(int $userId, string $token): string
    {
        return "leads_export:{$userId}:{$token}";
    }
}
