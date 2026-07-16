<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Modules\V8\Jobs\ProcessV8ConsultJob;
use App\Modules\V8\Models\V8ConsultJob;
use App\Modules\V8\Support\V8Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessV8ConsultJobSimulationConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('v8-test');
        Cache::flush();
        config([
            'queue.default' => 'sync',
            'logging.default' => 'null',
            'v8.oauth.base_url' => 'https://auth.v8.test',
            'v8.oauth.username' => 'user',
            'v8.oauth.password' => 'pass',
            'v8.oauth.audience' => 'aud',
            'v8.oauth.client_id' => 'client',
            'v8.bff.base_url' => 'https://bff.v8.test',
            'v8.storage.reports_disk' => 'v8-test',
            'v8.storage.dir_spool' => 'v8-spool',
            'v8.storage.dir_reports' => 'v8-reports',
            'v8.storage.final_prefix' => 'v8-consulta',
            'v8.http.min_interval_ms' => 0,
            'v8.http.min_interval_ms_phase1' => 0,
            'v8.http.min_interval_ms_phase2_status' => 0,
            'v8.http.min_interval_ms_phase2_simulation' => 0,
            'v8.logging.enabled' => false,
        ]);
    }

    public function test_it_uses_the_dynamic_config_id_in_simulation(): void
    {
        Http::fake([
            'https://auth.v8.test/oauth/token' => $this->tokenResponse(),
            'https://bff.v8.test/private-consignment/simulation/configs' => Http::response([
                'configs' => [[
                    'id' => 'dynamic-config-id',
                    'slug' => 'CLT Acelera - Seguro',
                ]],
            ]),
            'https://bff.v8.test/private-consignment/simulation' => Http::response([
                'id_simulation' => 'SIM-1',
            ]),
        ]);

        $job = $this->makeJob(['12345678909 Maria Silva 01/01/1990'], 'fase_2');
        Storage::disk('v8-test')->put(
            "v8-spool/v8-consulta_{$job->id}.consents.txt",
            "12345678909;Maria Silva;1990-01-01;CONSULT-1;reused;1\n"
        );

        ProcessV8ConsultJob::dispatchSync($job->id);

        $job->refresh();
        $this->assertSame('concluido', $job->status);
        $this->assertSame(1, $job->success_count);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://bff.v8.test/private-consignment/simulation'
                && $request->data()['config_id'] === 'dynamic-config-id';
        });
    }

    public function test_config_failure_is_written_for_pending_cpfs_without_duplicating_processed_rows(): void
    {
        Http::fake([
            'https://auth.v8.test/oauth/token' => $this->tokenResponse(),
            'https://bff.v8.test/private-consignment/simulation/configs' => Http::response(['configs' => []]),
        ]);

        $job = $this->makeJob([
            '12345678909 Maria Silva 01/01/1990',
            '52998224725 Joao Souza 02/02/1990',
            '11111111111 Pessoa Invalida 03/03/1990',
        ], null, [[
            'cpf' => '12345678909',
            'nome' => 'Maria Silva',
            'data_nascimento' => '1990-01-01',
            'status' => 'SUCESSO',
        ]]);

        ProcessV8ConsultJob::dispatchSync($job->id);

        $job->refresh();
        $rows = $this->finalRowsByCpf($job);
        $this->assertSame('falhou', $job->status);
        $this->assertSame(3, $job->total_cpfs);
        $this->assertSame(1, $job->success_count);
        $this->assertSame(2, $job->fail_count);
        $this->assertSame('SUCESSO', $rows['12345678909']['Status']);
        $this->assertSame('FALHOU', $rows['52998224725']['Status']);
        $this->assertStringContainsString('CLT Acelera - Seguro', $rows['52998224725']['Mensagem']);
        $this->assertSame('FALHOU', $rows['11111111111']['Status']);
        $this->assertCount(3, $rows);
    }

    public function test_it_fails_for_invalid_config_responses(): void
    {
        $cases = [
            [['configs' => [['id' => '1', 'slug' => 'Outro Produto']]], 200, 'não encontrada'],
            [['configs' => [
                ['id' => '1', 'slug' => 'CLT Acelera - Seguro'],
                ['id' => '2', 'slug' => 'CLT Acelera - Seguro'],
            ]], 200, 'duplicada'],
            [['invalid' => []], 200, 'Resposta inválida'],
            [['message' => 'indisponível'], 500, 'Falha ao obter configurações'],
        ];

        foreach ($cases as $index => [$body, $status, $expected]) {
            Cache::flush();
            $authUrl = "https://auth{$index}.v8.test";
            $bffUrl = "https://bff{$index}.v8.test";
            config([
                'v8.oauth.base_url' => $authUrl,
                'v8.bff.base_url' => $bffUrl,
            ]);
            Http::fake([
                "{$authUrl}/oauth/token" => $this->tokenResponse(),
                "{$bffUrl}/private-consignment/simulation/configs" => Http::response($body, $status),
            ]);

            $cpf = $index % 2 === 0 ? '12345678909' : '52998224725';
            $job = $this->makeJob(["{$cpf} Pessoa Teste 01/01/1990"]);
            ProcessV8ConsultJob::dispatchSync($job->id);

            $job->refresh();
            $rows = $this->finalRowsByCpf($job);
            $this->assertSame('falhou', $job->status);
            $this->assertStringContainsString($expected, $rows[$cpf]['Mensagem']);
        }
    }

    private function makeJob(array $inputs, ?string $phase = null, array $existingRows = []): V8ConsultJob
    {
        $job = V8ConsultJob::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Teste configuração V8',
            'status' => 'pendente',
            'phase' => $phase,
        ]);

        $spoolPath = "v8-spool/v8-consulta_{$job->id}.spool.csv";
        $inputsPath = "v8-spool/v8-consulta_{$job->id}.inputs.txt";
        $spool = implode(';', V8Schema::TITLES) . "\n";
        foreach ($existingRows as $row) {
            $spool .= implode(';', array_map(
                static fn ($column) => $row[$column] ?? '',
                V8Schema::COLS
            )) . "\n";
        }

        Storage::disk('v8-test')->put($spoolPath, $spool);
        Storage::disk('v8-test')->put($inputsPath, implode("\n", $inputs) . "\n");
        $job->update([
            'spool_path' => $spoolPath,
            'spool_inputs_path' => $inputsPath,
        ]);

        return $job;
    }

    private function finalRowsByCpf(V8ConsultJob $job): array
    {
        $content = Storage::disk('v8-test')->get($job->file_path);
        $lines = array_values(array_filter(preg_split('/\r?\n/', $content)));
        $headers = str_getcsv(ltrim(array_shift($lines), "\xEF\xBB\xBF"), ';');
        $rows = [];

        foreach ($lines as $line) {
            $values = array_pad(str_getcsv($line, ';'), count($headers), null);
            $row = array_combine($headers, array_slice($values, 0, count($headers)));
            $rows[$row['CPF']] = $row;
        }

        return $rows;
    }

    private function tokenResponse()
    {
        return Http::response([
            'access_token' => 'token',
            'expires_in' => 3600,
        ]);
    }
}
