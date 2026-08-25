<?php

declare(strict_types=1);

namespace App\Modules\DisparosWhatsappVendeai\Services;

use App\Modules\Leads\Filters\LeadFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RegisteredLeadsPreviewService
{
    /**
     * @param array<string, mixed> $filters
     * @return array{recipient_count: int}
     */
    public function preview(array $filters): array
    {
        $payload = $this->leadFilterPayload($filters);
        $fingerprint = hash('sha256', serialize($payload));
        $recipientCount = Cache::remember(
            "disparos-whatsapp-vendeai:registered-leads-preview:{$fingerprint}",
            now()->addSeconds(60),
            fn (): int => (int) LeadFilter::apply(new Request($payload), null, true)
                ->toBase()
                ->getCountForPagination(),
        );

        return ['recipient_count' => $recipientCount];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function leadFilterPayload(array $filters): array
    {
        $facta = (array) ($filters['facta'] ?? []);
        $mercantil = (array) ($filters['mercantil'] ?? []);
        $uy3 = (array) ($filters['uy3'] ?? []);

        return [
            'mode' => '360',
            'with_phones' => true,
            'selected_banks' => (array) ($filters['selected_banks'] ?? []),
            'bank_combination_mode' => (string) ($filters['combination_mode'] ?? 'any'),
            'birth_month' => (array) ($filters['birth_month'] ?? []),
            'facta_situacao' => $facta['situacao'] ?? null,
            'facta_consulta_from' => $facta['consulta_from'] ?? null,
            'facta_consulta_to' => $facta['consulta_to'] ?? null,
            'facta_meses_min' => $facta['meses_admissao_min'] ?? null,
            'facta_meses_max' => $facta['meses_admissao_max'] ?? null,
            'facta_margem_min' => $facta['margem_min'] ?? null,
            'facta_margem_max' => $facta['margem_max'] ?? null,
            'facta_numero_parcelas_min' => $facta['parcelas_min'] ?? null,
            'facta_numero_parcelas_max' => $facta['parcelas_max'] ?? null,
            'mercantil_situacao' => $mercantil['situacao'] ?? null,
            'mercantil_consulta_from' => $mercantil['consulta_from'] ?? null,
            'mercantil_consulta_to' => $mercantil['consulta_to'] ?? null,
            'mercantil_valor_parcela_min' => $mercantil['valor_parcela_min'] ?? null,
            'mercantil_valor_parcela_max' => $mercantil['valor_parcela_max'] ?? null,
            'mercantil_numero_parcelas_min' => $mercantil['parcelas_min'] ?? null,
            'mercantil_numero_parcelas_max' => $mercantil['parcelas_max'] ?? null,
            'uy3_situacao' => $uy3['situacao'] ?? null,
            'uy3_consulta_from' => $uy3['consulta_from'] ?? null,
            'uy3_consulta_to' => $uy3['consulta_to'] ?? null,
            'uy3_meses_admissao_min' => $uy3['meses_admissao_min'] ?? null,
            'uy3_meses_admissao_max' => $uy3['meses_admissao_max'] ?? null,
            'uy3_margem_min' => $uy3['margem_min'] ?? null,
            'uy3_margem_max' => $uy3['margem_max'] ?? null,
            'uy3_valor_liberado_min' => $uy3['valor_liberado_min'] ?? null,
            'uy3_valor_liberado_max' => $uy3['valor_liberado_max'] ?? null,
            'uy3_numero_parcelas_min' => $uy3['parcelas_min'] ?? null,
            'uy3_numero_parcelas_max' => $uy3['parcelas_max'] ?? null,
        ];
    }
}
