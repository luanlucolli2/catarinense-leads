<?php

declare(strict_types=1);

namespace App\Modules\Uy3\Services;

use App\Modules\Uy3\Models\Uy3WebhookPost;
use App\Modules\Uy3\Support\Uy3WebhookPayloadNormalizer;
use Illuminate\Validation\ValidationException;
use JsonException;

class BackfillUy3SnapshotsService
{
    public function __construct(
        private readonly Uy3SnapshotPersistService $persistService,
    ) {
    }

    public function handle(): array
    {
        $maxOriginalId = (int) (Uy3WebhookPost::query()->max('id') ?? 0);

        $stats = [
            'scanned' => 0,
            'persisted' => 0,
            'skipped' => 0,
        ];

        Uy3WebhookPost::query()
            ->where('id', '<=', $maxOriginalId)
            ->orderBy('id')
            ->chunkById(500, function ($posts) use (&$stats): void {
                foreach ($posts as $post) {
                    $stats['scanned']++;

                    $payload = $this->decodePayload($post->payload);
                    if ($payload === null) {
                        $stats['skipped']++;
                        continue;
                    }

                    try {
                        $payload = Uy3WebhookPayloadNormalizer::normalize($payload);
                    } catch (ValidationException) {
                        $stats['skipped']++;
                        continue;
                    }

                    $this->persistService->persist($payload, $post->received_at);
                    $stats['persisted']++;
                }
            });

        return $stats;
    }

    private function decodePayload(mixed $payload): ?array
    {
        if (is_array($payload) && ! array_is_list($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
