<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Modules\V8\Jobs\StoreV8ExternalReportJob;
use App\Modules\V8\Models\V8ConsultJob;
use App\Modules\V8\Services\V8ExternalApiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class V8ExternalApiExecutorTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('multi_consulta:short_links:token');
        config([
            'multi_consulta.base_url' => 'https://apibot.test',
            'multi_consulta.email' => 'user@test.com',
            'multi_consulta.password' => 'secret',
        ]);
    }

    public function test_it_creates_tracks_controls_and_downloads_an_external_job(): void
    {
        $user = User::factory()->create();
        $remoteStatus = 'scheduled';
        $expectedScheduledFor = '2030-01-01T13:00:00+00:00';
        Queue::fake();

        Http::fake(function ($request) use (&$remoteStatus, $expectedScheduledFor) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ($path === '/v1/auth/login') {
                return Http::response(['token' => 'external-token', 'expires_at' => now()->addHour()->toIso8601String()], 200);
            }

            if ($path === '/v1/jobs' && $request->method() === 'POST') {
                $this->assertSame("12345678909;Maria Silva;01/01/1990", $request->body());
                $this->assertSame('v8_clt', $query['module'] ?? null);
                $this->assertSame('Consulta externa', $query['title'] ?? null);
                $this->assertSame('true', $query['reuse_recent_consults'] ?? null);
                $this->assertSame($expectedScheduledFor, $query['scheduled_for'] ?? null);

                return Http::response($this->remoteJob('remote-1', $remoteStatus), 202);
            }

            if ($path === '/v1/jobs/remote-1' && $request->method() === 'GET') {
                $remoteStatus = 'running';

                return Http::response($this->remoteJob('remote-1', $remoteStatus), 200);
            }

            if ($path === '/v1/jobs/remote-1/pause') {
                $remoteStatus = 'paused';

                return Http::response($this->remoteJob('remote-1', $remoteStatus), 202);
            }

            if ($path === '/v1/jobs/remote-1/resume') {
                $remoteStatus = 'queued';

                return Http::response($this->remoteJob('remote-1', $remoteStatus), 202);
            }

            if ($path === '/v1/jobs/remote-1/cancel') {
                $remoteStatus = 'cancelled';

                return Http::response($this->remoteJob('remote-1', $remoteStatus), 202);
            }

            if ($path === '/v1/jobs/remote-1/preview') {
                return Http::response("CPF;Status\n12345678909;SUCESSO\n", 200, [
                    'Content-Disposition' => 'attachment; filename="previa.csv"',
                ]);
            }

            if ($path === '/v1/jobs/remote-1/report') {
                return Http::response("CPF;Status\n12345678909;SUCESSO\n", 200, [
                    'Content-Disposition' => 'attachment; filename="externo.csv"',
                ]);
            }

            if ($path === '/v1/jobs/remote-1' && $request->method() === 'DELETE') {
                return Http::response(null, 204);
            }

            return Http::response(['message' => 'not found'], 404);
        });

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v8/consult-jobs', [
            'title' => 'Consulta externa',
            'lines' => '12345678909;Maria Silva;01/01/1990',
            'reuse_recent_consults' => true,
            'run_at' => '2030-01-01T10:00',
            'timezone' => 'America/Sao_Paulo',
        ]);

        $created->assertAccepted()->assertJsonPath('status', 'agendado');
        $job = V8ConsultJob::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('api', $job->executor);
        $this->assertSame('remote-1', $job->external_job_id);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v8/consult-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('status', 'em_progresso')
            ->assertJsonPath('phase1_submitted_count', 2)
            ->assertJsonPath('phase1_not_eligible_count', 1)
            ->assertJsonPath('phase1_errors_count', 1)
            ->assertJsonPath('phase2_approved_count', 1)
            ->assertJsonPath('phase2_not_approved_count', 1)
            ->assertJsonPath('phase2_errors_count', 0);

        $this->actingAs($user, 'sanctum')->postJson("/api/v8/consult-jobs/{$job->id}/pause")
            ->assertAccepted()->assertJsonPath('status', 'pausado');
        $this->actingAs($user, 'sanctum')->postJson("/api/v8/consult-jobs/{$job->id}/resume")
            ->assertAccepted()->assertJsonPath('status', 'pendente');
        $this->actingAs($user, 'sanctum')->get("/api/v8/consult-jobs/{$job->id}/preview")
            ->assertOk()->assertDownload('previa.csv');
        $this->actingAs($user, 'sanctum')->postJson("/api/v8/consult-jobs/{$job->id}/cancel", ['reason' => 'teste'])
            ->assertOk()->assertJsonPath('status', 'cancelado');
        Queue::assertPushed(StoreV8ExternalReportJob::class, fn ($queued) => $queued->jobId === $job->id);
        (new StoreV8ExternalReportJob($job->id))->handle(app(V8ExternalApiService::class));
        $job->refresh();
        $this->assertNotNull($job->file_disk);
        $this->assertNotNull($job->file_path);
        $this->actingAs($user, 'sanctum')->get("/api/v8/consult-jobs/{$job->id}/download")
            ->assertOk()->assertDownload("{$job->id}-externo.csv");
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v8/consult-jobs/{$job->id}")
            ->assertNoContent();
    }

    public function test_it_does_not_keep_a_job_when_the_external_api_fails(): void
    {
        $user = User::factory()->create();
        Http::fake(['https://apibot.test/v1/auth/login' => Http::response(['message' => 'credenciais inválidas'], 401)]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v8/consult-jobs', [
            'title' => 'Consulta externa',
            'lines' => '12345678909;Maria Silva;01/01/1990',
        ])->assertStatus(502);

        $this->assertDatabaseMissing('v8_consult_jobs', ['user_id' => $user->id]);
    }

    public function test_it_blocks_any_v8_executor_while_a_job_is_active(): void
    {
        $user = User::factory()->create();
        V8ConsultJob::create([
            'user_id' => $user->id,
            'title' => 'Em andamento',
            'executor' => 'local',
            'status' => 'pausado',
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v8/consult-jobs', [
            'title' => 'Nova consulta',
            'lines' => '12345678909;Maria Silva;01/01/1990',
        ])->assertConflict();

        Http::assertNothingSent();
    }

    public function test_a_cancelled_job_does_not_block_a_new_consultation(): void
    {
        $user = User::factory()->create();
        V8ConsultJob::create([
            'user_id' => $user->id,
            'title' => 'Cancelado',
            'executor' => 'local',
            'status' => 'cancelado',
        ]);
        Http::fake([
            'https://apibot.test/v1/auth/login' => Http::response(['token' => 'external-token', 'expires_at' => now()->addHour()->toIso8601String()], 200),
            'https://apibot.test/v1/jobs*' => Http::response($this->remoteJob('remote-2', 'queued'), 202),
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v8/consult-jobs', [
            'title' => 'Nova consulta',
            'lines' => '12345678909;Maria Silva;01/01/1990',
        ])->assertAccepted();
    }

    private function remoteJob(string $id, string $status): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'phase' => $status === 'completed' || $status === 'cancelled' ? null : 'phase_1',
            'total_count' => 4,
            'metrics' => [
                'phase1.submitted' => 2,
                'phase1.not_eligible' => 1,
                'phase1.errors' => 1,
                'phase2.approved' => 1,
                'phase2.not_approved' => 1,
                'phase2.errors' => 0,
            ],
            'has_report' => $status === 'cancelled',
            'scheduled_for' => $status === 'scheduled' ? '2030-01-01T13:00:00Z' : null,
            'started_at' => $status === 'running' ? '2030-01-01T13:05:00Z' : null,
            'paused_at' => $status === 'paused' ? '2030-01-01T13:10:00Z' : null,
            'finished_at' => $status === 'cancelled' ? '2030-01-01T13:20:00Z' : null,
            'cancelled_at' => $status === 'cancelled' ? '2030-01-01T13:20:00Z' : null,
        ];
    }
}
