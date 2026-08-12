<?php

namespace Tests\Feature\FactaCLT;

use App\Models\User;
use App\Modules\FactaCLT\Jobs\StoreFactaCltExternalReportJob;
use App\Modules\FactaCLT\Models\FactaCltConsultJob;
use App\Modules\FactaCLT\Services\FactaCltExternalApiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FactaCltOfflineExternalApiTest extends TestCase
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

    public function test_it_creates_tracks_controls_and_persists_an_external_offline_job_report(): void
    {
        $user = User::factory()->create();
        $remoteStatus = 'scheduled';
        Queue::fake();

        Http::fake(function ($request) use (&$remoteStatus) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ($path === '/v1/auth/login') {
                return Http::response(['token' => 'external-token', 'expires_at' => now()->addHour()->toIso8601String()], 200);
            }

            if ($path === '/v1/jobs' && $request->method() === 'POST') {
                $this->assertSame("12345678909\n98765432100", $request->body());
                $this->assertSame('facta_clt', $query['module'] ?? null);
                $this->assertSame('offline', $query['mode'] ?? null);
                $this->assertSame('Consulta Facta Offline', $query['title'] ?? null);
                $this->assertSame('2030-01-01T13:00:00+00:00', $query['scheduled_for'] ?? null);

                return Http::response($this->remoteJob('remote-1', $remoteStatus), 202);
            }

            if ($path === '/v1/jobs/remote-1' && $request->method() === 'GET') {
                if ($remoteStatus === 'scheduled') {
                    $remoteStatus = 'running';
                }

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

        $this->actingAs($user, 'sanctum')->postJson('/api/facta-clt/consult-jobs', [
            'title' => 'Consulta Facta Offline',
            'cpfs' => "12345678909\n98765432100",
            'variant' => 'offline',
            'run_at' => '2030-01-01T10:00',
            'timezone' => 'America/Sao_Paulo',
        ])->assertAccepted()->assertJsonPath('status', 'agendado');

        $job = FactaCltConsultJob::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('api', $job->executor);
        $this->assertSame('remote-1', $job->external_job_id);
        $this->assertNull($job->spool_path);

        $this->actingAs($user, 'sanctum')->getJson("/api/facta-clt/consult-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('status', 'em_progresso')
            ->assertJsonPath('elegivel_count', 2)
            ->assertJsonPath('phase2_total', 2)
            ->assertJsonPath('phase2_fail_count', 1);
        $this->actingAs($user, 'sanctum')->postJson("/api/facta-clt/consult-jobs/{$job->id}/pause")
            ->assertAccepted()->assertJsonPath('status', 'pausado');
        $this->actingAs($user, 'sanctum')->postJson("/api/facta-clt/consult-jobs/{$job->id}/resume")
            ->assertAccepted()->assertJsonPath('status', 'pendente');
        $this->actingAs($user, 'sanctum')->get("/api/facta-clt/consult-jobs/{$job->id}/preview")
            ->assertOk()->assertDownload('previa.csv');
        $this->actingAs($user, 'sanctum')->postJson("/api/facta-clt/consult-jobs/{$job->id}/cancel")
            ->assertOk()->assertJsonPath('status', 'cancelado');

        Queue::assertPushed(StoreFactaCltExternalReportJob::class, fn ($queued) => $queued->jobId === $job->id);
        (new StoreFactaCltExternalReportJob($job->id))->handle(app(FactaCltExternalApiService::class));
        $job->refresh();
        $this->assertNotNull($job->file_path);
        $this->actingAs($user, 'sanctum')->get("/api/facta-clt/consult-jobs/{$job->id}/download")
            ->assertOk()->assertDownload("{$job->id}-externo.csv");
        $this->actingAs($user, 'sanctum')->deleteJson("/api/facta-clt/consult-jobs/{$job->id}")
            ->assertNoContent();
    }

    public function test_it_does_not_keep_an_offline_job_when_the_external_api_fails(): void
    {
        $user = User::factory()->create();
        Http::fake(['https://apibot.test/v1/auth/login' => Http::response(['message' => 'credenciais inválidas'], 401)]);

        $this->actingAs($user, 'sanctum')->postJson('/api/facta-clt/consult-jobs', [
            'title' => 'Consulta Facta Offline',
            'cpfs' => '12345678909',
            'variant' => 'offline',
        ])->assertStatus(502);

        $this->assertDatabaseMissing('facta_clt_consult_jobs', ['user_id' => $user->id]);
    }

    public function test_online_and_hybrid_jobs_remain_local(): void
    {
        $user = User::factory()->create();
        Queue::fake();

        $this->actingAs($user, 'sanctum')->postJson('/api/facta-clt/consult-jobs', [
            'title' => 'Consulta Facta Online',
            'cpfs' => '12345678909',
            'variant' => 'online',
        ])->assertAccepted();

        $job = FactaCltConsultJob::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('local', $job->executor);
        $this->assertNotNull($job->spool_path);
        Http::assertNothingSent();
    }

    private function remoteJob(string $id, string $status): array
    {
        return [
            'id' => $id,
            'module' => 'facta_clt',
            'mode' => 'offline',
            'status' => $status,
            'phase' => in_array($status, ['completed', 'cancelled'], true) ? null : 'phase_1',
            'total_count' => 3,
            'metrics' => [
                'phase1.eligible' => 2,
                'phase1.ineligible' => 0,
                'phase1.not_found' => 0,
                'phase1.errors' => 1,
                'phase2.total' => 2,
                'phase2.approved' => 1,
                'phase2.not_approved' => 0,
                'phase2.errors' => 1,
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
