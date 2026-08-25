<?php

declare(strict_types=1);

namespace App\Modules\DisparosWhatsappVendeai\Services;

use App\Modules\DisparosWhatsappVendeai\Exceptions\MailingInboxesConfigurationException;
use App\Modules\DisparosWhatsappVendeai\Exceptions\MailingInboxesRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MailingInboxesService
{
    private const CACHE_KEY = 'disparos-whatsapp-vendeai:mailing-inboxes';

    /** @return array{inboxes: array<int, array<string, mixed>>} */
    public function list(bool $refresh = false): array
    {
        $this->ensureConfigured();

        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $ttl = max(1, (int) config('vendeai.mailing.inboxes_cache_seconds', 300));

        return Cache::remember(self::CACHE_KEY, now()->addSeconds($ttl), fn (): array => $this->requestInboxes());
    }

    private function ensureConfigured(): void
    {
        if (blank(config('vendeai.mailing.base_url')) || blank(config('vendeai.mailing.account_id')) || blank(config('vendeai.mailing.crm_api_access_token'))) {
            throw new MailingInboxesConfigurationException();
        }
    }

    /** @return array{inboxes: array<int, array<string, mixed>>} */
    private function requestInboxes(): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(max(1, (int) config('vendeai.mailing.timeout_seconds', 15)))
                ->post(sprintf('%s/api/message-handler/mailing/inboxes/', config('vendeai.mailing.base_url')), [
                    'account_id' => config('vendeai.mailing.account_id'),
                    'crm_api_access_token' => config('vendeai.mailing.crm_api_access_token'),
                ]);
        } catch (ConnectionException) {
            throw new MailingInboxesRequestException();
        }

        if (! $response->successful() || ! is_array($response->json('inboxes'))) {
            throw new MailingInboxesRequestException();
        }

        return ['inboxes' => array_values(array_filter(array_map(
            fn (mixed $inbox): ?array => $this->normalizeInbox($inbox),
            $response->json('inboxes'),
        )))];
    }

    /** @return array<string, mixed>|null */
    private function normalizeInbox(mixed $inbox): ?array
    {
        if (! is_array($inbox) || strtolower((string) ($inbox['channel'] ?? '')) !== 'whatsapp') {
            return null;
        }

        $id = trim((string) ($inbox['id'] ?? ''));
        $name = trim((string) ($inbox['name'] ?? ''));
        $phoneNumber = trim((string) ($inbox['phone_number'] ?? ''));
        if ($id === '' || $name === '' || $phoneNumber === '' || ! is_array($inbox['templates'] ?? null)) {
            return null;
        }

        $templates = array_values(array_filter(array_map(
            fn (mixed $template): ?array => $this->normalizeTemplate($template),
            $inbox['templates'],
        )));

        return [
            'id' => $id,
            'name' => $name,
            'phone_number' => $phoneNumber,
            'templates' => $templates,
        ];
    }

    /** @return array<string, mixed>|null */
    private function normalizeTemplate(mixed $template): ?array
    {
        if (! is_array($template)) {
            return null;
        }

        $id = trim((string) ($template['id'] ?? ''));
        $name = trim((string) ($template['name'] ?? ''));
        if ($id === '' || $name === '') {
            return null;
        }

        $headerType = strtoupper((string) ($template['header_type'] ?? ''));
        if (! in_array($headerType, ['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $headerType = null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'status' => strtoupper(trim((string) ($template['status'] ?? ''))),
            'category' => (string) ($template['category'] ?? ''),
            'language' => (string) ($template['language'] ?? ''),
            'body' => (string) ($template['body'] ?? ''),
            'variables' => $this->normalizeValues($template['variables'] ?? []),
            'header_type' => $headerType,
            'header_variables' => $this->normalizeValues($template['header_variables'] ?? []),
            'header_text' => isset($template['header_text']) ? (string) $template['header_text'] : null,
        ];
    }

    /** @return array<int, string> */
    private function normalizeValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $values), static fn (string $value): bool => $value !== ''));
    }
}
