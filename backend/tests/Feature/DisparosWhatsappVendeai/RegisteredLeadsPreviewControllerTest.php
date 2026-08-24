<?php

declare(strict_types=1);

namespace Tests\Feature\DisparosWhatsappVendeai;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegisteredLeadsPreviewControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_preview_requires_authentication(): void
    {
        $this->postJson('/api/disparos-whatsapp-vendeai/leads/preview', [])->assertUnauthorized();
    }

    public function test_preview_counts_only_one_phone_recipient_per_matching_lead(): void
    {
        $this->authenticate();
        Cache::flush();
        $this->createLead('87000000001', ['fone1' => '47999990001', 'fone2' => '47999990002']);
        $this->createLead('87000000002');
        $this->createFactaSnapshot('87000000001');
        $this->createFactaSnapshot('87000000002');

        $this->postJson('/api/disparos-whatsapp-vendeai/leads/preview', [
            'selected_banks' => ['facta'],
            'combination_mode' => 'any',
            'facta' => ['situacao' => 'aprovado'],
        ])->assertOk()->assertExactJson(['recipient_count' => 1]);
    }

    public function test_preview_uses_facta_mercantil_uy3_ranges_and_bank_combination(): void
    {
        $this->authenticate();
        Cache::flush();
        $this->createLead('87000000011', ['fone1' => '47999990011']);
        $this->createLead('87000000012', ['fone1' => '47999990012']);
        $this->createLead('87000000013', ['fone1' => '47999990013']);

        $this->createFactaSnapshot('87000000011', ['meses_admissao' => 8, 'margem_disponivel' => 150, 'politica_credito_prazo_maximo_disponivel' => 24]);
        $this->createMercantilSnapshot('87000000011', ['data_hora_origem' => now()->subDay(), 'valor_parcela' => 140, 'quantidade_parcelas' => 18]);
        $this->createMercantilSnapshot('87000000012', ['data_hora_origem' => now()->subDay(), 'valor_parcela' => 140, 'quantidade_parcelas' => 18]);
        $this->createUy3Snapshot('87000000013', ['data_admissao' => now()->subMonths(8)->toDateString(), 'margem_disponivel' => 120, 'valor_liberado' => 1500, 'numero_parcelas' => 12]);

        $facta = ['situacao' => 'aprovado', 'meses_admissao_min' => 6, 'meses_admissao_max' => 10, 'margem_min' => 100, 'margem_max' => 200, 'parcelas_min' => 12, 'parcelas_max' => 30];
        $mercantil = ['situacao' => 'aprovado', 'consulta_from' => now()->subDays(2)->toDateString(), 'consulta_to' => now()->toDateString(), 'valor_parcela_min' => 100, 'valor_parcela_max' => 200, 'parcelas_min' => 12, 'parcelas_max' => 24];

        $this->postJson('/api/disparos-whatsapp-vendeai/leads/preview', [
            'selected_banks' => ['facta', 'mercantil'], 'combination_mode' => 'all', 'facta' => $facta, 'mercantil' => $mercantil,
        ])->assertOk()->assertExactJson(['recipient_count' => 1]);

        $this->postJson('/api/disparos-whatsapp-vendeai/leads/preview', [
            'selected_banks' => ['facta', 'mercantil'], 'combination_mode' => 'any', 'facta' => $facta, 'mercantil' => $mercantil,
        ])->assertOk()->assertExactJson(['recipient_count' => 2]);

        $this->postJson('/api/disparos-whatsapp-vendeai/leads/preview', [
            'selected_banks' => ['uy3'], 'combination_mode' => 'any',
            'uy3' => ['situacao' => 'aprovado', 'meses_admissao_min' => 6, 'meses_admissao_max' => 10, 'margem_min' => 100, 'margem_max' => 200, 'valor_liberado_min' => 1000, 'valor_liberado_max' => 2000, 'parcelas_min' => 10, 'parcelas_max' => 15],
        ])->assertOk()->assertExactJson(['recipient_count' => 1]);
    }

    public function test_preview_rejects_bank_without_filter_and_inverted_ranges(): void
    {
        $this->authenticate();

        $this->postJson('/api/disparos-whatsapp-vendeai/leads/preview', [
            'selected_banks' => ['facta'], 'combination_mode' => 'any', 'facta' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('facta');

        $this->postJson('/api/disparos-whatsapp-vendeai/leads/preview', [
            'selected_banks' => ['mercantil'], 'combination_mode' => 'any',
            'mercantil' => ['valor_parcela_min' => 200, 'valor_parcela_max' => 100],
        ])->assertUnprocessable()->assertJsonValidationErrors('mercantil.valor_parcela_max');
    }

    private function authenticate(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    /** @param array<string, mixed> $overrides */
    private function createLead(string $cpf, array $overrides = []): void
    {
        DB::table('leads')->insert(array_merge([
            'cpf' => $cpf, 'nome' => "Lead {$cpf}", 'data_nascimento' => '1990-01-01',
            'fone1' => null, 'classe_fone1' => null, 'fone2' => null, 'classe_fone2' => null,
            'fone3' => null, 'classe_fone3' => null, 'fone4' => null, 'classe_fone4' => null,
            'consulta' => null, 'data_atualizacao' => now(), 'saldo' => null, 'libera' => null,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function createFactaSnapshot(string $cpf, array $overrides = []): void
    {
        DB::table('facta_clt_snapshots')->insert(array_merge([
            'cpf' => $cpf, 'lead_id' => null, 'nome' => null, 'elegivel' => 1,
            'not_found' => 0, 'politica_credito_aprovado' => 1, 'consulted_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function createMercantilSnapshot(string $cpf, array $overrides = []): void
    {
        DB::table('mercantil_snapshots')->insert(array_merge([
            'cpf' => $cpf, 'status' => 'SUCESSO', 'data_hora_origem' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function createUy3Snapshot(string $cpf, array $overrides = []): void
    {
        DB::table('uy3_snapshots')->insert(array_merge([
            'cpf' => $cpf, 'elegivel_emprestimo' => 1, 'updated_at' => now(),
        ], $overrides));
    }
}
