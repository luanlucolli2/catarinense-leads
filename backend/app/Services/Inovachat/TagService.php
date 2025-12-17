<?php

namespace App\Services\Inovachat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TagService
{
    /**
     * Adiciona tags a um ticket (API /api/tags/add).
     *
     * @param  string  $ticketId
     * @param  int[]   $tagIds
     */
    public function addTagsToTicket(string $ticketId, array $tagIds): bool
    {
        $baseUrl = rtrim((string) config('inovachat.api.base_url'), '/');
        $token   = (string) config('inovachat.api.connection_token');

        if ($baseUrl === '' || $token === '') {
            Log::error('Inovachat TagService not configured', [
                'base_url' => $baseUrl,
                'has_token' => $token !== '',
            ]);
            return false;
        }

        $tagIds = array_values(array_filter(array_map('intval', $tagIds), fn ($id) => $id > 0));
        if ($ticketId === '' || empty($tagIds)) {
            return false;
        }

        $url = $baseUrl . '/api/tags/add';

        $payload = [
            'ticketId' => (int) $ticketId, // doc usa number
            'tags' => array_map(fn (int $id) => ['id' => $id], $tagIds),
        ];

        $timeout      = (int) config('inovachat.http.timeout', 10);
        $connect      = (int) config('inovachat.http.connect_timeout', 5);
        $retries      = (int) config('inovachat.http.retry', 1);
        $retryDelayMs = (int) config('inovachat.http.retry_delay_ms', 200);

        try {
            $request = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->connectTimeout($connect);

            $response = null;

            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                $response = $request->post($url, $payload);

                if ($response->successful()) {
                    Log::info('Inovachat tags added to ticket', [
                        'ticketId' => $ticketId,
                        'tagIds'   => $tagIds,
                    ]);
                    return true;
                }

                if ($attempt < $retries) {
                    usleep($retryDelayMs * 1000);
                }
            }

            Log::warning('Inovachat add tags failed', [
                'ticketId'    => $ticketId,
                'tagIds'      => $tagIds,
                'http_status' => $response?->status(),
                'body'        => $response?->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Inovachat add tags exception', [
                'ticketId'   => $ticketId,
                'tagIds'     => $tagIds,
                'exception'  => $e->getMessage(),
            ]);
            return false;
        }
    }
}
