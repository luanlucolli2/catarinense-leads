<?php

declare(strict_types=1);

namespace Tests\Feature\Lemit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LemitPoolControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        config(['lemit.sample.max_quantity' => 5000]);
    }

    public function test_preview_allows_phone_only_filter(): void
    {
        $this->createLead('11111111111', ['fone1' => '47999990001']);
        $this->createLead('22222222222');
        $this->createLead('33333333333', ['fone2' => '47999990002']);

        $response = $this->postJson('/api/lemit/pool/preview', [
            'bank_combination_mode' => 'all',
            'with_phones' => true,
            'selected_banks' => [],
        ]);

        $response->assertOk()->assertJson([
            'pool_size' => 2,
            'pool_with_phones' => 2,
            'pool_without_phones' => 0,
        ]);
    }

    public function test_preview_rejects_selected_bank_without_own_filter(): void
    {
        $response = $this->postJson('/api/lemit/pool/preview', [
            'bank_combination_mode' => 'all',
            'selected_banks' => ['facta'],
            'facta' => [],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('errors.clt.0', 'Preencha ao menos um filtro no bloco CLT Facta.');
    }

    public function test_preview_supports_all_and_any_combination_with_two_banks(): void
    {
        $this->createLead('40000000001');
        $this->createLead('40000000002');
        $this->createLead('40000000003');

        $this->createCltSnapshot('40000000001', ['elegivel' => 1, 'not_found' => 0, 'politica_credito_aprovado' => 1]);
        $this->createMercantilSnapshot('40000000001', ['status' => 'SUCESSO']);

        $this->createCltSnapshot('40000000002', ['elegivel' => 1, 'not_found' => 0, 'politica_credito_aprovado' => 1]);
        $this->createMercantilSnapshot('40000000003', ['status' => 'SUCESSO']);

        $payload = [
            'selected_banks' => ['facta', 'mercantil'],
            'bank_combination_mode' => 'all',
            'facta' => ['facta_situacao' => 'aprovado'],
            'mercantil' => ['mercantil_situacao' => 'aprovado'],
        ];

        $this->postJson('/api/lemit/pool/preview', $payload)
            ->assertOk()
            ->assertJsonPath('pool_size', 1);

        $payload['bank_combination_mode'] = 'any';

        $this->postJson('/api/lemit/pool/preview', $payload)
            ->assertOk()
            ->assertJsonPath('pool_size', 3);
    }

    public function test_preview_filters_clt_by_status_months_margin_and_parcelas(): void
    {
        $this->createLead('50000000001');
        $this->createLead('50000000002');
        $this->createLead('50000000003');

        $this->createCltSnapshot('50000000001', [
            'elegivel' => 1,
            'not_found' => 0,
            'politica_credito_aprovado' => 1,
            'meses_admissao' => 11,
            'margem_disponivel' => 150.50,
            'politica_credito_prazo_maximo_disponivel' => 36,
        ]);

        $this->createCltSnapshot('50000000002', [
            'elegivel' => 0,
            'not_found' => 0,
            'politica_credito_aprovado' => 0,
            'meses_admissao' => 12,
            'margem_disponivel' => 170.00,
            'politica_credito_prazo_maximo_disponivel' => 36,
        ]);

        $this->createCltSnapshot('50000000003', [
            'elegivel' => 1,
            'not_found' => 0,
            'politica_credito_aprovado' => 0,
            'meses_admissao' => 5,
            'margem_disponivel' => 90.00,
            'politica_credito_prazo_maximo_disponivel' => 12,
        ]);

        $payload = [
            'selected_banks' => ['facta'],
            'bank_combination_mode' => 'all',
            'facta' => [
                'facta_situacao' => 'aprovado',
                'facta_meses_admissao_min' => 10,
                'facta_meses_admissao_max' => 12,
                'facta_margem_min' => 100,
                'facta_margem_max' => 180,
                'facta_numero_parcelas_min' => 30,
                'facta_numero_parcelas_max' => 40,
            ],
        ];

        $this->postJson('/api/lemit/pool/preview', $payload)
            ->assertOk()
            ->assertJsonPath('pool_size', 1);

        $payload['facta'] = [
            'facta_situacao' => 'nao_aprovado',
            'facta_meses_admissao_min' => 10,
            'facta_meses_admissao_max' => 12,
        ];

        $this->postJson('/api/lemit/pool/preview', $payload)
            ->assertOk()
            ->assertJsonPath('pool_size', 1);
    }

    public function test_preview_filters_mercantil_by_status_dates_values_and_parcelas(): void
    {
        $this->createLead('60000000001');
        $this->createLead('60000000002');
        $this->createLead('60000000003');

        $this->createMercantilSnapshot('60000000001', [
            'status' => 'SUCESSO',
            'data_hora_origem' => now()->subDays(2),
            'valor_parcela' => 150.00,
            'valor_liberado' => 3200.00,
            'quantidade_parcelas' => 24,
        ]);

        $this->createMercantilSnapshot('60000000002', [
            'status' => 'ERRO_ANALISE',
            'data_hora_origem' => now()->subDays(1),
            'valor_parcela' => 140.00,
            'valor_liberado' => 2500.00,
            'quantidade_parcelas' => 18,
        ]);

        $this->createMercantilSnapshot('60000000003', [
            'status' => 'SUCESSO',
            'data_hora_origem' => now()->subDays(30),
            'valor_parcela' => 99.00,
            'valor_liberado' => 900.00,
            'quantidade_parcelas' => 6,
        ]);

        $payload = [
            'selected_banks' => ['mercantil'],
            'bank_combination_mode' => 'all',
            'mercantil' => [
                'mercantil_situacao' => 'aprovado',
                'mercantil_consulta_from' => now()->subDays(5)->toDateString(),
                'mercantil_consulta_to' => now()->toDateString(),
                'mercantil_valor_parcela_min' => 100,
                'mercantil_valor_parcela_max' => 200,
                'mercantil_numero_parcelas_min' => 20,
                'mercantil_numero_parcelas_max' => 30,
            ],
        ];

        $this->postJson('/api/lemit/pool/preview', $payload)
            ->assertOk()
            ->assertJsonPath('pool_size', 1);

        $payload['mercantil'] = [
            'mercantil_situacao' => 'nao_aprovado',
            'mercantil_consulta_from' => now()->subDays(5)->toDateString(),
            'mercantil_consulta_to' => now()->toDateString(),
        ];

        $this->postJson('/api/lemit/pool/preview', $payload)
            ->assertOk()
            ->assertJsonPath('pool_size', 1);
    }

    public function test_preview_filters_uy3_by_status_months_margin_value_and_parcelas(): void
    {
        $this->createLead('70000000001');
        $this->createLead('70000000002');
        $this->createLead('70000000003');

        $this->createUy3Snapshot('70000000001', [
            'elegivel_emprestimo' => 1,
            'data_admissao' => now()->subMonths(8)->toDateString(),
            'margem_disponivel' => 160.00,
            'valor_liberado' => 1800.00,
            'numero_parcelas' => 12,
        ]);

        $this->createUy3Snapshot('70000000002', [
            'elegivel_emprestimo' => 0,
            'data_admissao' => now()->subMonths(7)->toDateString(),
            'margem_disponivel' => 140.00,
            'valor_liberado' => 1700.00,
            'numero_parcelas' => 10,
        ]);

        $this->createUy3Snapshot('70000000003', [
            'elegivel_emprestimo' => 1,
            'data_admissao' => now()->subMonths(2)->toDateString(),
            'margem_disponivel' => 80.00,
            'valor_liberado' => 500.00,
            'numero_parcelas' => 4,
        ]);

        $payload = [
            'selected_banks' => ['uy3'],
            'bank_combination_mode' => 'all',
            'uy3' => [
                'uy3_situacao' => 'aprovado',
                'uy3_meses_admissao_min' => 6,
                'uy3_meses_admissao_max' => 10,
                'uy3_margem_min' => 100,
                'uy3_margem_max' => 200,
                'uy3_valor_liberado_min' => 1500,
                'uy3_valor_liberado_max' => 2000,
                'uy3_numero_parcelas_min' => 10,
                'uy3_numero_parcelas_max' => 15,
            ],
        ];

        $this->postJson('/api/lemit/pool/preview', $payload)
            ->assertOk()
            ->assertJsonPath('pool_size', 1);

        $payload['uy3'] = [
            'uy3_situacao' => 'nao_aprovado',
            'uy3_meses_admissao_min' => 6,
            'uy3_meses_admissao_max' => 10,
        ];

        $this->postJson('/api/lemit/pool/preview', $payload)
            ->assertOk()
            ->assertJsonPath('pool_size', 1);
    }

    public function test_preview_filters_uy3_months_with_mysql_boundary_equivalent_range(): void
    {
        Carbon::setTestNow('2026-07-01 12:00:00');

        try {
            $this->createLead('71000000001');
            $this->createLead('71000000002');
            $this->createLead('71000000003');
            $this->createLead('71000000004');

            $this->createUy3Snapshot('71000000001', [
                'data_admissao' => '2025-08-01',
            ]);

            $this->createUy3Snapshot('71000000002', [
                'data_admissao' => '2025-08-31',
            ]);

            $this->createUy3Snapshot('71000000003', [
                'data_admissao' => '2025-09-01',
            ]);

            $this->createUy3Snapshot('71000000004', [
                'data_admissao' => '2025-09-02',
            ]);

            $this->postJson('/api/lemit/pool/preview', [
                'selected_banks' => ['uy3'],
                'bank_combination_mode' => 'all',
                'uy3' => [
                    'uy3_meses_admissao_min' => 10,
                    'uy3_meses_admissao_max' => 10,
                ],
            ])
                ->assertOk()
                ->assertJsonPath('pool_size', 2);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_sample_respects_filtered_pool_does_not_repeat_leads_and_rejects_invalid_quantity(): void
    {
        foreach (range(1, 5) as $suffix) {
            $this->createLead(sprintf('8000000000%d', $suffix), [
                'fone1' => '4799999000' . $suffix,
            ]);
        }

        $payload = [
            'selected_banks' => [],
            'bank_combination_mode' => 'all',
            'with_phones' => true,
            'quantity' => 3,
        ];

        $response = $this->postJson('/api/lemit/pool/sample', $payload);

        $response
            ->assertOk()
            ->assertJsonPath('pool_size', 5)
            ->assertJsonPath('sampled_quantity', 3)
            ->assertJsonCount(3, 'items');

        $items = $response->json('items');
        $leadIds = array_column($items, 'lead_id');

        $this->assertCount(3, array_unique($leadIds));

        $payload['quantity'] = 6;

        $this->postJson('/api/lemit/pool/sample', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'A quantidade solicitada excede a base filtrada atual.');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createLead(string $cpf, array $overrides = []): void
    {
        DB::table('leads')->insert(array_merge([
            'cpf' => $cpf,
            'nome' => 'Lead ' . $cpf,
            'data_nascimento' => '1990-01-01',
            'fone1' => null,
            'classe_fone1' => null,
            'fone2' => null,
            'classe_fone2' => null,
            'fone3' => null,
            'classe_fone3' => null,
            'fone4' => null,
            'classe_fone4' => null,
            'consulta' => null,
            'data_atualizacao' => now(),
            'saldo' => null,
            'libera' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createCltSnapshot(string $cpf, array $overrides = []): void
    {
        DB::table('facta_clt_snapshots')->insert(array_merge([
            'cpf' => $cpf,
            'lead_id' => null,
            'nome' => 'CLT ' . $cpf,
            'elegivel' => 1,
            'data_nascimento' => '1990-01-01',
            'idade' => 35,
            'sexo' => 'F',
            'data_admissao' => now()->subMonths(12)->toDateString(),
            'meses_admissao' => 12,
            'valor_renda' => 3000.00,
            'valor_base_margem' => 600.00,
            'margem_disponivel' => 200.00,
            'valor_max_prestacao' => 350.00,
            'categoria_trabalhador_codigo' => '101',
            'inicio_atividade_empregador' => now()->subYears(3)->toDateString(),
            'qtd_emprestimos_ativos_suspensos' => 0,
            'emprestimos_legados' => 0,
            'not_found' => 0,
            'job_id' => null,
            'updated_at' => now(),
            'consulted_at' => now()->subDay(),
            'matricula' => 'MAT-' . substr($cpf, -4),
            'politica_credito_aprovado' => 1,
            'politica_credito_mensagem' => null,
            'politica_credito_valor_maximo_disponivel' => 5000.00,
            'politica_credito_prazo_maximo_disponivel' => 36,
            'politica_credito_data_consulta' => now()->subDay(),
            'politica_credito_tabela_aprovada' => 'PADRAO',
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createMercantilSnapshot(string $cpf, array $overrides = []): void
    {
        DB::table('mercantil_snapshots')->insert(array_merge([
            'cpf' => $cpf,
            'nome' => 'Mercantil ' . $cpf,
            'data_nascimento' => '1990-01-01',
            'status' => 'SUCESSO',
            'mensagem_erro' => null,
            'data_hora_origem' => now()->subDay(),
            'valor_financiado' => 4000.00,
            'valor_iof' => 200.00,
            'data_primeiro_vencimento' => now()->addMonth()->toDateString(),
            'valor_emprestimo' => 3800.00,
            'quantidade_parcelas' => 24,
            'valor_liberado' => 3200.00,
            'taxa_juros_mes' => 2.1000,
            'valor_parcela' => 150.00,
            'job_id' => null,
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createUy3Snapshot(string $cpf, array $overrides = []): void
    {
        DB::table('uy3_snapshots')->insert(array_merge([
            'cpf' => $cpf,
            'type_webhook' => 'LEADS_CLT',
            'status' => 'ATIVA',
            'data_admissao' => now()->subMonths(8)->toDateString(),
            'valor_liberado' => 1800.00,
            'numero_parcelas' => 12,
            'codigo_requisicao' => 'req-' . substr($cpf, -4),
            'margem_disponivel' => 160.00,
            'elegivel_emprestimo' => 1,
            'numero_inscricao_empregador' => '12345678',
            'pessoa_exposta_politicamente_codigo' => 0,
            'data_hora_validade_solicitacao' => now()->addDay(),
            'is_mei' => 0,
            'active_fgts_debts' => null,
            'all_branch_employees' => null,
            'is_judicial_recovery' => 0,
            'updated_at' => now(),
        ], $overrides));
    }
}
