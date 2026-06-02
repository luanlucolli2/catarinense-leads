<?php

namespace App\Modules\Vendeai\Services;

use App\Modules\Vendeai\Models\VendeaiLead;
use App\Modules\Vendeai\Models\VendeaiProposalCreatedWebhook;
use App\Modules\Vendeai\Support\VendeaiProductKey;
use Illuminate\Support\Facades\DB;

class BackfillVendeaiLeadProductKeysService
{
    private const SHARED_FIELDS = [
        'account_id',
        'chat_id',
        'last_event',
        'chat_product',
        'stage',
        'tags',
        'campaign',
        'inbox_phone_number',
        'product_being_processed',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_cpf',
        'customer_birth_date',
        'customer_mother_name',
        'first_received_at',
        'last_received_at',
        'last_payload',
        'created_at',
        'updated_at',
    ];

    private const SIMULATION_FIELDS = [
        'simulation_product',
        'simulation_bank',
        'simulation_liquid_value',
        'simulation_number_of_payments',
        'simulation_installment_value',
        'simulation_monthly_fee',
        'simulation_table_name',
        'simulation_table_id',
        'simulation_best_liquid_value',
        'simulation_best_table_id',
        'simulation_table_details',
        'simulation_received_at',
    ];

    private const PROPOSAL_FIELDS = [
        'proposal_id',
        'proposal_number',
        'proposal_status',
        'previous_proposal_status',
        'proposal_bank',
        'proposal_product',
        'proposal_liquid_value',
        'proposal_gross_value',
        'proposal_number_of_payments',
        'proposal_installment_value',
        'proposal_table_name',
        'proposal_table_id',
        'proposal_formalization_link',
        'proposal_created_at',
        'proposal_status_updated_at',
    ];

    public function handle(): array
    {
        $maxOriginalId = (int) (VendeaiLead::query()->max('id') ?? 0);

        $stats = [
            'processed' => 0,
            'updated' => 0,
            'duplicated' => 0,
            'attempts_relinked' => 0,
        ];

        VendeaiLead::query()
            ->where('id', '<=', $maxOriginalId)
            ->orderBy('id')
            ->chunkById(100, function ($leads) use (&$stats): void {
                foreach ($leads as $lead) {
                    $stats['processed']++;
                    $stats['duplicated'] += $this->backfillLead($lead);
                    $stats['updated']++;
                }
            });

        $stats['attempts_relinked'] = $this->relinkAttempts();

        return $stats;
    }

    private function backfillLead(VendeaiLead $lead): int
    {
        $products = VendeaiProductKey::collectFromLead($lead);

        if ($products === []) {
            return 0;
        }

        $duplicates = 0;
        $base = $lead->toArray();
        $primaryProduct = array_shift($products);

        $lead->fill($this->attributesForProduct($base, $primaryProduct, count($products) > 0));
        $lead->save();

        foreach ($products as $product) {
            $clone = new VendeaiLead();
            $clone->forceFill($this->attributesForProduct($base, $product, true));
            $clone->save();
            $duplicates++;
        }

        return $duplicates;
    }

    private function relinkAttempts(): int
    {
        $leadMap = VendeaiLead::query()
            ->whereNotNull('product_key')
            ->get(['id', 'account_id', 'chat_id', 'product_key'])
            ->mapWithKeys(fn (VendeaiLead $lead): array => [
                $this->leadMapKey($lead->account_id, $lead->chat_id, $lead->product_key) => $lead->id,
            ]);

        $updated = 0;

        VendeaiProposalCreatedWebhook::query()
            ->orderBy('id')
            ->chunkById(100, function ($attempts) use ($leadMap, &$updated): void {
                foreach ($attempts as $attempt) {
                    $payload = $attempt->raw_payload;
                    if (! is_array($payload)) {
                        continue;
                    }

                    $accountId = $this->stringOrNull(data_get($payload, 'chat_summary.account_id'), 50);
                    $chatId = $this->stringOrNull(data_get($payload, 'chat_summary.chat_id'), 100);
                    $productKey = VendeaiProductKey::resolveFromPayload($payload);

                    if ($accountId === null || $chatId === null || $productKey === null) {
                        continue;
                    }

                    $leadId = $leadMap->get($this->leadMapKey($accountId, $chatId, $productKey));

                    if ($leadId === null || (int) $attempt->vendeai_lead_id === (int) $leadId) {
                        continue;
                    }

                    $attempt->forceFill(['vendeai_lead_id' => $leadId])->save();
                    $updated++;
                }
            });

        return $updated;
    }

    private function attributesForProduct(array $base, string $product, bool $splitProductSpecificFields): array
    {
        $attributes = [];

        foreach (self::SHARED_FIELDS as $field) {
            $attributes[$field] = $base[$field] ?? null;
        }

        foreach (self::SIMULATION_FIELDS as $field) {
            $attributes[$field] = $splitProductSpecificFields && VendeaiProductKey::canonicalize($base['simulation_product'] ?? null) !== $product
                ? null
                : ($base[$field] ?? null);
        }

        foreach (self::PROPOSAL_FIELDS as $field) {
            $attributes[$field] = $splitProductSpecificFields && VendeaiProductKey::canonicalize($base['proposal_product'] ?? null) !== $product
                ? null
                : ($base[$field] ?? null);
        }

        $attributes['product_key'] = $product;
        $attributes['chat_product'] = VendeaiProductKey::canonicalize($base['chat_product'] ?? null) === $product
            ? $product
            : ($splitProductSpecificFields ? null : ($base['chat_product'] ?? null));
        $attributes['product_being_processed'] = VendeaiProductKey::canonicalize($base['product_being_processed'] ?? null) === $product
            ? $product
            : ($splitProductSpecificFields ? null : ($base['product_being_processed'] ?? null));

        return $attributes;
    }

    private function leadMapKey(string $accountId, string $chatId, string $productKey): string
    {
        return implode('|', [$accountId, $chatId, $productKey]);
    }

    private function stringOrNull(mixed $value, ?int $maxLength = null): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $maxLength === null ? $value : mb_substr($value, 0, $maxLength);
    }
}
