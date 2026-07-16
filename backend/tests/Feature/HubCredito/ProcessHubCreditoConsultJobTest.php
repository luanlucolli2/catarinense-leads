<?php

namespace Tests\Feature\HubCredito;

use App\Models\User;
use App\Modules\HubCredito\Jobs\FinalizeHubCreditoConsultReportJob;
use App\Modules\HubCredito\Jobs\ProcessHubCreditoConsultJob;
use App\Modules\HubCredito\Models\HubCreditoConsultJob;
use App\Modules\HubCredito\Services\HubCreditoApiService;
use App\Modules\HubCredito\Support\HubCreditoFiles;
use App\Modules\HubCredito\Support\HubCreditoSchema;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProcessHubCreditoConsultJobTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('hubcredito-test');
        config([
            'hubcredito.auth.base_url' => 'https://api.hubcredito.test',
            'hubcredito.auth.username' => 'user@test',
            'hubcredito.auth.password' => 'secret',
            'hubcredito.storage.reports_disk' => 'hubcredito-test',
            'hubcredito.job.phase1_request_interval_ms' => 0,
            'hubcredito.job.phase2_timeout_seconds' => 60,
            'hubcredito.job.phase2_start_delay_seconds' => 0,
            'hubcredito.job.poll_delay_seconds' => 0,
            'hubcredito.http.min_interval_ms' => 0,
            'hubcredito.http.retry' => 0,
        ]);
    }

    #[DataProvider('readyStatusProvider')]
    public function test_it_processes_ready_presimulacao_and_chooses_offer_with_highest_release(int $statusId): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response($this->loginResponse(), 200),
            'https://api.hubcredito.test/api/presimulacao' => Http::response([
                'value' => ['id' => 101, 'idStatus' => 0],
                'errors' => [],
            ], 200),
            'https://api.hubcredito.test/api/PreSimulacao*' => Http::response([
                'itens' => [[
                    'id' => 101,
                    'cpf' => '12345678909',
                    'lojaId' => 15895,
                    'numeroParcelas' => 12,
                    'valor' => 5000,
                    'idStatus' => $statusId,
                    'status' => (string) $statusId,
                    'statusDescricao' => 'Pronta',
                    'mensagemErro' => null,
                ]],
                'numeroPagina' => 1,
                'tamanhoPagina' => 100,
                'totalPaginas' => 1,
                'temProximaPagina' => false,
            ], 200),
            'https://api.hubcredito.test/api/Clt/simular' => Http::response([
                'value' => [
                    [
                        'simulacaoId' => 'SIM-LOW',
                        'idCotacao' => 'COT-LOW',
                        'opcaoProposta' => [
                            'idProposta' => 'PROP-LOW',
                            'valorDesembolsoTrabalhador' => 3500,
                            'valorDesembolsoTotal' => 3600,
                            'valorParcela' => 300,
                            'numeroParcelas' => 12,
                            'taxaJuros' => 2.1,
                            'valorSeguro' => 0,
                            'comSeguro' => false,
                        ],
                    ],
                    [
                        'simulacaoId' => 'SIM-HIGH',
                        'idCotacao' => 'COT-HIGH',
                        'opcaoProposta' => [
                            'idProposta' => 'PROP-HIGH',
                            'valorDesembolsoTrabalhador' => 4200,
                            'valorDesembolsoTotal' => 4300,
                            'valorParcela' => 320,
                            'numeroParcelas' => 12,
                            'taxaJuros' => 1.9,
                            'valorSeguro' => 20,
                            'comSeguro' => true,
                        ],
                    ],
                ],
                'errors' => [],
            ], 200),
        ]);

        $job = $this->makePendingJob(['12345678909 Fulano da Silva 01/02/1990']);

        ProcessHubCreditoConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(1, $job->aprovado_count);
        $this->assertNotNull($job->file_path);
        $this->assertTrue(Storage::disk('hubcredito-test')->exists($job->file_path));
        $content = Storage::disk('hubcredito-test')->get($job->file_path);
        $this->assertStringContainsString('Aprovado', $content);
        $this->assertStringContainsString('4200', $content);

        Carbon::setTestNow();
    }

    #[DataProvider('vinculoStatusProvider')]
    public function test_it_marks_vinculo_statuses_as_nao_aprovado(int $statusId): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response($this->loginResponse(), 200),
            'https://api.hubcredito.test/api/presimulacao' => Http::response([
                'value' => ['id' => 101, 'idStatus' => 0],
            ], 200),
            'https://api.hubcredito.test/api/PreSimulacao*' => Http::response([
                'itens' => [[
                    'id' => 101,
                    'cpf' => '12345678909',
                    'lojaId' => 15895,
                    'numeroParcelas' => 12,
                    'valor' => 5000,
                    'idStatus' => $statusId,
                    'status' => (string) $statusId,
                    'statusDescricao' => 'Vínculo pendente',
                    'mensagemErro' => null,
                ]],
                'numeroPagina' => 1,
                'tamanhoPagina' => 100,
                'totalPaginas' => 1,
                'temProximaPagina' => false,
            ], 200),
        ]);

        $job = $this->makePendingJob(['12345678909 Fulano da Silva 01/02/1990']);

        ProcessHubCreditoConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(1, $job->nao_aprovado_count);
        $content = Storage::disk('hubcredito-test')->get($job->file_path);
        $this->assertStringContainsString('Não Aprovado', $content);

        Carbon::setTestNow();
    }

    #[DataProvider('naoAprovadoStatusProvider')]
    public function test_it_marks_terminal_presimulacao_statuses_as_nao_aprovado(int $statusId): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response($this->loginResponse(), 200),
            'https://api.hubcredito.test/api/presimulacao' => Http::response([
                'value' => ['id' => 101, 'idStatus' => 0],
            ], 200),
            'https://api.hubcredito.test/api/PreSimulacao*' => Http::response([
                'itens' => [[
                    'id' => 101,
                    'cpf' => '12345678909',
                    'lojaId' => 15895,
                    'numeroParcelas' => 12,
                    'valor' => 5000,
                    'idStatus' => $statusId,
                    'status' => (string) $statusId,
                    'statusDescricao' => 'Sem opção',
                    'mensagemErro' => 'Erro de negócio',
                ]],
                'numeroPagina' => 1,
                'tamanhoPagina' => 100,
                'totalPaginas' => 1,
                'temProximaPagina' => false,
            ], 200),
        ]);

        $job = $this->makePendingJob(['12345678909 Fulano da Silva 01/02/1990']);

        ProcessHubCreditoConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(1, $job->nao_aprovado_count);
        $content = Storage::disk('hubcredito-test')->get($job->file_path);
        $this->assertStringContainsString('Não Aprovado', $content);
        $this->assertStringContainsString('Erro de negócio', $content);

        Carbon::setTestNow();
    }

    public function test_it_times_out_pending_statuses_as_nao_aprovado(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response($this->loginResponse(), 200),
            'https://api.hubcredito.test/api/presimulacao' => Http::response([
                'value' => ['id' => 101, 'idStatus' => 0],
            ], 200),
            'https://api.hubcredito.test/api/PreSimulacao*' => Http::response([
                'itens' => [[
                    'id' => 101,
                    'cpf' => '12345678909',
                    'lojaId' => 15895,
                    'numeroParcelas' => 12,
                    'valor' => 5000,
                    'idStatus' => 13,
                    'status' => '13',
                    'statusDescricao' => 'Aguardando retorno',
                    'mensagemErro' => null,
                ]],
                'numeroPagina' => 1,
                'tamanhoPagina' => 100,
                'totalPaginas' => 1,
                'temProximaPagina' => false,
            ], 200),
        ]);

        $job = $this->makePendingJob(['12345678909 Fulano da Silva 01/02/1990'], Carbon::now()->subMinutes(2));

        ProcessHubCreditoConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(1, $job->nao_aprovado_count);
        $content = Storage::disk('hubcredito-test')->get($job->file_path);
        $this->assertStringContainsString('Não Aprovado', $content);
        $this->assertStringContainsString('Timeout aguardando processamento da pré-simulação.', $content);

        Carbon::setTestNow();
    }

    public function test_it_marks_simulation_response_with_errors_as_nao_aprovado(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response($this->loginResponse(), 200),
            'https://api.hubcredito.test/api/presimulacao' => Http::response([
                'value' => ['id' => 101, 'idStatus' => 0],
            ], 200),
            'https://api.hubcredito.test/api/PreSimulacao*' => Http::response([
                'itens' => [[
                    'id' => 101,
                    'cpf' => '12345678909',
                    'lojaId' => 15895,
                    'numeroParcelas' => 12,
                    'valor' => 5000,
                    'idStatus' => 6,
                    'status' => '6',
                    'statusDescricao' => 'Pronta',
                    'mensagemErro' => null,
                ]],
                'numeroPagina' => 1,
                'tamanhoPagina' => 100,
                'totalPaginas' => 1,
                'temProximaPagina' => false,
            ], 200),
            'https://api.hubcredito.test/api/Clt/simular' => Http::response([
                'hasSuccess' => true,
                'hasError' => false,
                'errors' => ['Não foram encontraradas simulações CPF, tente novamente.'],
                'httpStatusCode' => 'OK',
            ], 200),
        ]);

        $job = $this->makePendingJob(['12345678909 Fulano da Silva 01/02/1990']);

        ProcessHubCreditoConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(1, $job->nao_aprovado_count);
        $content = Storage::disk('hubcredito-test')->get($job->file_path);
        $this->assertStringContainsString('Não Aprovado', $content);
        $this->assertStringContainsString('Não foram encontraradas simulações CPF, tente novamente.', $content);

        Carbon::setTestNow();
    }

    public function test_it_marks_invalid_input_line_as_falhou(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response($this->loginResponse(), 200),
        ]);

        $job = $this->makePendingJob(['abc']);

        ProcessHubCreditoConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(0, $job->aprovado_count);
        $this->assertSame(0, $job->nao_aprovado_count);
        $this->assertSame(1, $job->fail_count);
        $content = Storage::disk('hubcredito-test')->get($job->file_path);
        $this->assertStringContainsString('Falhou', $content);
        $this->assertStringContainsString('Linha inválida.', $content);

        Carbon::setTestNow();
    }

    public function test_it_marks_presimulacao_retriable_failure_as_falhou(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');
        config(['hubcredito.http.retry' => 1]);

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response($this->loginResponse(), 200),
            'https://api.hubcredito.test/api/presimulacao' => Http::sequence()
                ->push(['message' => 'rate limited'], 429)
                ->push(['message' => 'still rate limited'], 429),
        ]);

        $job = $this->makePendingJob(['12345678909 Fulano da Silva 01/02/1990']);

        ProcessHubCreditoConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(0, $job->aprovado_count);
        $this->assertSame(0, $job->nao_aprovado_count);
        $this->assertSame(1, $job->fail_count);
        $content = Storage::disk('hubcredito-test')->get($job->file_path);
        $this->assertStringContainsString('Falhou', $content);
        $this->assertStringContainsString('rate limited', $content);

        Carbon::setTestNow();
    }

    public function test_it_marks_simulation_retriable_failure_as_falhou(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');
        config(['hubcredito.http.retry' => 1]);

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response($this->loginResponse(), 200),
            'https://api.hubcredito.test/api/presimulacao' => Http::response([
                'value' => ['id' => 101, 'idStatus' => 0],
            ], 200),
            'https://api.hubcredito.test/api/PreSimulacao*' => Http::response([
                'itens' => [[
                    'id' => 101,
                    'cpf' => '12345678909',
                    'lojaId' => 15895,
                    'numeroParcelas' => 12,
                    'valor' => 5000,
                    'idStatus' => 6,
                    'status' => '6',
                    'statusDescricao' => 'Pronta',
                    'mensagemErro' => null,
                ]],
                'numeroPagina' => 1,
                'tamanhoPagina' => 100,
                'totalPaginas' => 1,
                'temProximaPagina' => false,
            ], 200),
            'https://api.hubcredito.test/api/Clt/simular' => Http::sequence()
                ->push(['message' => 'erro interno'], 500)
                ->push(['message' => 'erro interno'], 500),
        ]);

        $job = $this->makePendingJob(['12345678909 Fulano da Silva 01/02/1990']);

        ProcessHubCreditoConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(0, $job->aprovado_count);
        $this->assertSame(0, $job->nao_aprovado_count);
        $this->assertSame(1, $job->fail_count);
        $content = Storage::disk('hubcredito-test')->get($job->file_path);
        $this->assertStringContainsString('Falhou', $content);
        $this->assertStringContainsString('erro interno', $content);

        Carbon::setTestNow();
    }

    public function test_it_dispatches_report_finalization_asynchronously(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');
        Queue::fake();

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response($this->loginResponse(), 200),
            'https://api.hubcredito.test/api/presimulacao' => Http::response([
                'value' => ['id' => 101, 'idStatus' => 0],
            ], 200),
            'https://api.hubcredito.test/api/PreSimulacao*' => Http::response([
                'itens' => [[
                    'id' => 101,
                    'cpf' => '12345678909',
                    'lojaId' => 15895,
                    'numeroParcelas' => 12,
                    'valor' => 5000,
                    'idStatus' => 2,
                    'status' => '2',
                    'statusDescricao' => 'Sem opção',
                    'mensagemErro' => 'Erro de negócio',
                ]],
                'numeroPagina' => 1,
                'tamanhoPagina' => 100,
                'totalPaginas' => 1,
                'temProximaPagina' => false,
            ], 200),
        ]);

        $job = $this->makePendingJob(['12345678909 Fulano da Silva 01/02/1990']);

        (new ProcessHubCreditoConsultJob($job->id))->handle(app(HubCreditoApiService::class));

        Queue::assertPushed(FinalizeHubCreditoConsultReportJob::class);
        Carbon::setTestNow();
    }

    public function test_it_preserves_unresolved_pending_entries_between_rounds(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');
        config(['hubcredito.job.phase2_timeout_seconds' => 600]);

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response($this->loginResponse(), 200),
            'https://api.hubcredito.test/api/presimulacao' => static function () {
                static $id = 100;
                $id++;

                return Http::response([
                    'value' => ['id' => $id, 'idStatus' => 0],
                ], 200);
            },
            'https://api.hubcredito.test/api/PreSimulacao*' => static function () {
                static $call = 0;
                $call++;

                return match ($call) {
                    1 => Http::response([
                        'value' => [[
                            'id' => 101,
                            'cpf' => '12345678909',
                            'lojaId' => 15895,
                            'numeroParcelas' => 12,
                            'valor' => 5000,
                            'idStatus' => 2,
                            'status' => '2',
                            'mensagemErro' => 'Sem opção',
                        ], [
                            'id' => 102,
                            'cpf' => '98765432100',
                            'lojaId' => 15895,
                            'numeroParcelas' => 12,
                            'valor' => 5000,
                            'idStatus' => 13,
                            'status' => '13',
                            'mensagemErro' => null,
                        ]],
                    ], 200),
                    default => Http::response([
                        'value' => [[
                            'id' => 102,
                            'cpf' => '98765432100',
                            'lojaId' => 15895,
                            'numeroParcelas' => 12,
                            'valor' => 5000,
                            'idStatus' => 6,
                            'status' => '6',
                            'mensagemErro' => null,
                        ]],
                    ], 200),
                };
            },
            'https://api.hubcredito.test/api/Clt/simular' => Http::response([
                'value' => [[
                    'opcaoProposta' => [
                        'valorDesembolsoTrabalhador' => 4200,
                        'valorDesembolsoTotal' => 4300,
                        'valorParcela' => 320,
                        'numeroParcelas' => 12,
                        'taxaJuros' => 1.9,
                        'valorSeguro' => 20,
                        'comSeguro' => true,
                    ],
                ]],
                'errors' => [],
            ], 200),
        ]);

        $job = $this->makePendingJob([
            '12345678909 Fulano da Silva 01/02/1990',
            '98765432100 Beltrano da Silva 01/02/1990',
        ]);

        ProcessHubCreditoConsultJob::dispatchSync($job->id);

        $job->refresh();
        $content = Storage::disk('hubcredito-test')->get($job->file_path);

        $this->assertSame('concluido', $job->status);
        $this->assertSame(1, $job->aprovado_count);
        $this->assertSame(1, $job->nao_aprovado_count);
        $this->assertSame(0, $job->fail_count);
        $this->assertStringContainsString('Aprovado', $content);
        $this->assertStringContainsString('Não Aprovado', $content);

        Carbon::setTestNow();
    }

    public function test_it_processes_large_batches_without_loading_all_entries_in_memory(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response($this->loginResponse(), 200),
            'https://api.hubcredito.test/api/presimulacao' => static fn () => Http::response([
                'hasSuccess' => false,
                'hasError' => true,
                'errors' => ['Cliente sem margem disponível para o produto.'],
                'httpStatusCode' => 'BadRequest',
            ], 400),
        ]);

        $lines = [];
        for ($i = 0; $i < 150; $i++) {
            $lines[] = sprintf('%s Fulano da Silva 01/02/1990', $this->validCpfForIndex($i));
        }

        $job = $this->makePendingJob($lines);

        ProcessHubCreditoConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(150, $job->total_cpfs);
        $this->assertSame(150, $job->nao_aprovado_count);
        $this->assertSame(0, $job->fail_count);
        $this->assertTrue(Storage::disk('hubcredito-test')->exists((string) $job->file_path));

        Carbon::setTestNow();
    }

    public function test_it_cleans_only_deterministic_job_files_after_finalization(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        Storage::disk('hubcredito-test')->put('hubcredito-spool/unrelated.csv', 'keep');

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response($this->loginResponse(), 200),
            'https://api.hubcredito.test/api/presimulacao' => Http::response([
                'value' => ['id' => 101, 'idStatus' => 0],
            ], 200),
            'https://api.hubcredito.test/api/PreSimulacao*' => Http::response([
                'itens' => [[
                    'id' => 101,
                    'cpf' => '12345678909',
                    'lojaId' => 15895,
                    'numeroParcelas' => 12,
                    'valor' => 5000,
                    'idStatus' => 2,
                    'status' => '2',
                    'mensagemErro' => 'Sem opção',
                ]],
                'numeroPagina' => 1,
                'tamanhoPagina' => 100,
                'totalPaginas' => 1,
                'temProximaPagina' => false,
            ], 200),
        ]);

        $job = $this->makePendingJob(['12345678909 Fulano da Silva 01/02/1990']);

        ProcessHubCreditoConsultJob::dispatchSync($job->id);

        $job->refresh();
        $shardPath = HubCreditoFiles::pendingShardPath('hubcredito-spool', 'hubcredito-consulta', $job->id, abs(crc32('101')) % HubCreditoFiles::PENDING_SHARD_COUNT);

        $this->assertTrue(Storage::disk('hubcredito-test')->exists('hubcredito-spool/unrelated.csv'));
        $this->assertFalse(Storage::disk('hubcredito-test')->exists($shardPath));
        $this->assertNull($job->spool_path);
        $this->assertNull($job->spool_inputs_path);

        Carbon::setTestNow();
    }

    public static function readyStatusProvider(): array
    {
        return [
            'simulacoes_disponiveis' => [6],
            'concluida' => [12],
        ];
    }

    public static function vinculoStatusProvider(): array
    {
        return [
            'escolher_vinculo' => [3],
            'selecionando_vinculo' => [4],
        ];
    }

    public static function naoAprovadoStatusProvider(): array
    {
        return [
            'nao_elegivel' => [2],
            'sem_opcoes' => [5],
            'erro' => [7],
            'cancelada' => [8],
            'nao_encontrado_dataprev' => [9],
            'tipo_operacao_inativo' => [10],
            'aguardando_assinatura' => [11],
            'empresa_irregular' => [14],
            'dados_invalidos' => [15],
        ];
    }

    private function makePendingJob(array $lines, ?Carbon $startedAt = null): HubCreditoConsultJob
    {
        $user = User::factory()->create();
        $job = HubCreditoConsultJob::create([
            'user_id' => $user->id,
            'title' => 'Teste Hubcredito',
            'status' => 'pendente',
            'started_at' => $startedAt,
        ]);

        $spoolPath = "hubcredito-spool/hubcredito-consulta_{$job->id}.spool.csv";
        $inputsPath = "hubcredito-spool/hubcredito-consulta_{$job->id}.inputs.txt";

        Storage::disk('hubcredito-test')->put($spoolPath, implode(';', HubCreditoSchema::TITLES) . "\n");
        Storage::disk('hubcredito-test')->put($inputsPath, implode("\n", $lines) . "\n");

        $job->update([
            'spool_path' => $spoolPath,
            'spool_inputs_path' => $inputsPath,
        ]);

        return $job;
    }

    private function validCpfForIndex(int $index): string
    {
        $base = str_pad((string) ($index + 1), 9, '0', STR_PAD_LEFT);
        $digits = array_map('intval', str_split($base));

        $sum = 0;
        foreach ($digits as $i => $digit) {
            $sum += $digit * (10 - $i);
        }
        $rest = $sum % 11;
        $digits[] = $rest < 2 ? 0 : 11 - $rest;

        $sum = 0;
        foreach ($digits as $i => $digit) {
            $sum += $digit * (11 - $i);
        }
        $rest = $sum % 11;
        $digits[] = $rest < 2 ? 0 : 11 - $rest;

        return implode('', $digits);
    }

    private function loginResponse(): array
    {
        return [
            'value' => [
                'id' => 'user-id-1',
                'token' => [
                    'accessToken' => 'token-1',
                    'refreshToken' => 'refresh-1',
                    'expiration' => '2026-07-08 11:00:00',
                ],
            ],
        ];
    }
}
