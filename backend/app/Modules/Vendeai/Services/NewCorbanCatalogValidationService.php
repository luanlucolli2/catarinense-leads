<?php

namespace App\Modules\Vendeai\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class NewCorbanCatalogValidationService
{
    public function handle(): array
    {
        $errors = [];
        $warnings = [];
        $baseUrl = rtrim((string) config('newcorban.base_url'), '/');
        $token = trim((string) config('newcorban.api_token'));

        if ($baseUrl === '') {
            $errors[] = 'NEWCORBAN_BASE_URL not configured.';
        }

        if ($token === '') {
            $errors[] = 'NEWCORBAN_API_TOKEN not configured.';
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'errors' => $errors,
                'warnings' => [],
                'catalogs' => [],
            ];
        }

        $catalogItems = [];
        $catalogCounts = [];

        foreach ((array) config('newcorban.catalogs', []) as $name => $path) {
            $items = $this->fetchCatalog($baseUrl, $token, (string) $path, $warnings);
            $catalogItems[$name] = $items;
            $catalogCounts[$name] = count($items);
        }

        $errors = array_merge(
            $errors,
            $this->validateConfiguredIds('id', $this->configuredBankIds(), $catalogItems['banks'] ?? [], 'banks'),
            $this->validateConfiguredIds('id', $this->configuredPromoterIds(), $catalogItems['promoters'] ?? [], 'promoters'),
            $this->validateConfiguredIds('id', $this->configuredProductIds(), $catalogItems['products'] ?? [], 'products'),
            $this->validateConfiguredIds('id', $this->configuredCovenantIds(), $catalogItems['covenants'] ?? [], 'covenants'),
            $this->validateConfiguredIds('id', $this->configuredDefaultIds('team_id'), $catalogItems['teams'] ?? [], 'teams'),
            $this->validateConfiguredIds('id', $this->configuredDefaultIds('origin_id'), $catalogItems['proposal-origins'] ?? [], 'proposal-origins'),
            $this->validateConfiguredIds('id', $this->configuredDefaultIds('franchise_id'), $catalogItems['franchises'] ?? [], 'franchises'),
        );

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'catalogs' => $catalogCounts,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCatalog(string $baseUrl, string $token, string $path, array &$warnings): array
    {
        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(max(1, (int) config('newcorban.timeout', 15)))
            ->get($baseUrl . $path, [
                'page' => 1,
                'per_page' => 500,
            ]);

        if ($response->status() === 422 && $path === '/tables') {
            $warnings[] = 'Catalog /tables skipped: API returned HTTP 422 for unscoped request.';

            return [];
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Failed to fetch %s: HTTP %d',
                $path,
                $response->status(),
            ));
        }

        $body = $response->json();

        if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
            throw new RuntimeException(sprintf('Unexpected response format for %s.', $path));
        }

        return $body['data'];
    }

    /**
     * @param  list<string>  $configuredIds
     * @param  array<int, array<string, mixed>>  $items
     * @return list<string>
     */
    private function validateConfiguredIds(string $field, array $configuredIds, array $items, string $catalogName): array
    {
        if ($configuredIds === []) {
            return [];
        }

        $available = [];

        foreach ($items as $item) {
            $value = $this->stringOrNull($item[$field] ?? null);

            if ($value !== null) {
                $available[$value] = true;
            }
        }

        $errors = [];

        foreach ($configuredIds as $configuredId) {
            if (! isset($available[$configuredId])) {
                $errors[] = sprintf('Configured %s "%s" not found in %s catalog.', $field, $configuredId, $catalogName);
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function configuredBankIds(): array
    {
        return $this->uniqueConfigValues((array) config('newcorban.banks', []), 'bank_id');
    }

    /**
     * @return list<string>
     */
    private function configuredPromoterIds(): array
    {
        return $this->uniqueConfigValues((array) config('newcorban.banks', []), 'promoter_id');
    }

    /**
     * @return list<string>
     */
    private function configuredProductIds(): array
    {
        return $this->uniqueConfigValues((array) config('newcorban.products', []), 'product_id');
    }

    /**
     * @return list<string>
     */
    private function configuredCovenantIds(): array
    {
        return $this->uniqueConfigValues((array) config('newcorban.products', []), 'covenant_id');
    }

    /**
     * @return list<string>
     */
    private function configuredDefaultIds(string $key): array
    {
        $value = $this->stringOrNull(config('newcorban.defaults.' . $key));

        return $value === null ? [] : [$value];
    }

    /**
     * @param  array<string, array<string, mixed>>  $config
     * @return list<string>
     */
    private function uniqueConfigValues(array $config, string $field): array
    {
        $values = [];

        foreach ($config as $item) {
            if (! is_array($item)) {
                continue;
            }

            $value = $this->stringOrNull($item[$field] ?? null);

            if ($value !== null) {
                $values[$value] = true;
            }
        }

        return array_keys($values);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
