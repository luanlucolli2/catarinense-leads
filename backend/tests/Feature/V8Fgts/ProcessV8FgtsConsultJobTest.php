<?php

namespace Tests\Feature\V8Fgts;

use App\Models\User;
use App\Modules\V8Fgts\Jobs\ProcessV8FgtsConsultJob;
use App\Modules\V8Fgts\Models\V8FgtsConsultJob;
use App\Modules\V8Fgts\Support\V8FgtsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessV8FgtsConsultJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_a_happy_path_job_until_simulation(): void
    {
        Storage::fake('v8-fgts-test');

        config([
            'v8.oauth.base_url' => 'https://auth.v8.test',
            'v8.oauth.username' => 'user',
            'v8.oauth.password' => 'pass',
            'v8.oauth.audience' => 'aud',
            'v8.oauth.client_id' => 'client',
            'v8_fgts.storage.reports_disk' => 'v8-fgts-test',
            'v8_fgts.bff.base_url' => 'https://bff.v8.test',
            'v8_fgts.job.start_retry_delay_seconds' => 0,
            'v8_fgts.job.polling_round_delay_seconds' => 0,
        ]);

        Http::fake([
            'https://auth.v8.test/oauth/token' => Http::response([
                'access_token' => 'shared-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'https://bff.v8.test/fgts/balance*' => function ($request) {
                if ($request->method() === 'POST') {
                    return Http::response(null, 200);
                }

                return Http::response([
                    'data' => [[
                        'id' => 'BAL-123',
                        'documentNumber' => '12345678909',
                        'status' => 'success',
                        'statusInfo' => null,
                        'createdAt' => '2026-06-03T10:00:02Z',
                        'updatedAt' => '2026-06-03T10:00:02Z',
                        'amount' => 304.66,
                        'provider' => 'bms',
                        'periods' => [
                            ['amount' => 180.16, 'dueDate' => '2030-06-01'],
                            ['amount' => 124.50, 'dueDate' => '2031-06-01'],
                        ],
                    ]],
                ], 200);
            },
            'https://bff.v8.test/fgts/simulations/fees' => Http::response([
                [
                    'active' => true,
                    'simulation_fees' => [
                        'label' => 'normal',
                        'id_simulation_fees' => 'FEE-123',
                    ],
                ],
            ], 200),
            'https://bff.v8.test/fgts/simulations' => Http::response([
                'id' => 'SIM-123',
                'availableBalance' => 85.45,
                'emissionAmount' => 117.67,
                'totalBalance' => 304.66,
                'totalInstallments' => 2,
                'tax' => 1.8,
                'cet' => 0.0242,
                'annualCet' => 33.23,
                'iof' => 3.98,
                'tc' => 24,
            ], 200),
        ]);

        $user = User::factory()->create();
        $job = V8FgtsConsultJob::create([
            'user_id' => $user->id,
            'title' => 'Teste FGTS V8',
            'status' => 'pendente',
        ]);

        $spoolPath = 'v8-fgts-spool/teste.spool.csv';
        $cpfsPath = 'v8-fgts-spool/teste.cpfs.txt';
        Storage::disk('v8-fgts-test')->put($spoolPath, implode(';', V8FgtsSchema::TITLES) . "\n");
        Storage::disk('v8-fgts-test')->put($cpfsPath, "12345678909\n");
        $job->update([
            'spool_path' => $spoolPath,
            'spool_cpfs_path' => $cpfsPath,
        ]);

        ProcessV8FgtsConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(1, $job->success_count);
        $this->assertNotNull($job->file_path);
        $this->assertTrue(Storage::disk('v8-fgts-test')->exists($job->file_path));
        $this->assertStringContainsString('SIM-123', Storage::disk('v8-fgts-test')->get($job->file_path));
    }
}
