<?php

namespace Tests\Feature\Presenca;

use App\Models\User;
use App\Modules\Presenca\Jobs\StorePresencaExternalReportJob;
use App\Modules\Presenca\Models\PresencaConsultJob;
use App\Modules\Presenca\Services\PresencaExternalApiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PresencaExternalApiExecutorTest extends TestCase
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

    public function test_it_creates_tracks_controls_and_persists_an_external_job_report(): void
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
                $this->assertSame("12345678909;Maria Silva", $request->body());
                $this->assertSame('presenca_clt', $query['module'] ?? null);
                $this->assertSame('Consulta externa', $query['title'] ?? null);
                $this->assertSame('2030-01-01T13:00:00+00:00', $query['scheduled_for'] ?? null);

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

        $this->actingAs($user, 'sanctum')->postJson('/api/presenca/consult-jobs', [
            'title' => 'Consulta externa',
            'lines' => '12345678909;Maria Silva',
            'run_at' => '2030-01-01T10:00',
            'timezone' => 'America/Sao_Paulo',
        ])->assertAccepted()->assertJsonPath('status', 'agendado');

        $job = PresencaConsultJob::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('api', $job->executor);
        $this->assertSame('remote-1', $job->external_job_id);

        $this->actingAs($user, 'sanctum')->getJson("/api/presenca/consult-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('status', 'em_progresso')
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('policy_declined_count', 1)
            ->assertJsonPath('fail_count', 1);
        $this->actingAs($user, 'sanctum')->postJson("/api/presenca/consult-jobs/{$job->id}/pause")
            ->assertAccepted()->assertJsonPath('status', 'pausado');
        $this->actingAs($user, 'sanctum')->postJson("/api/presenca/consult-jobs/{$job->id}/resume")
            ->assertAccepted()->assertJsonPath('status', 'pendente');
        $this->actingAs($user, 'sanctum')->get("/api/presenca/consult-jobs/{$job->id}/preview")
            ->assertOk()->assertDownload('previa.csv');
        $this->actingAs($user, 'sanctum')->postJson("/api/presenca/consult-jobs/{$job->id}/cancel", ['reason' => 'teste'])
            ->assertOk()->assertJsonPath('status', 'cancelado');

        Queue::assertPushed(StorePresencaExternalReportJob::class, fn ($queued) => $queued->jobId === $job->id);
        (new StorePresencaExternalReportJob($job->id))->handle(app(PresencaExternalApiService::class));
        $job->refresh();
        $this->assertNotNull($job->file_path);
        $this->actingAs($user, 'sanctum')->get("/api/presenca/consult-jobs/{$job->id}/download")
            ->assertOk()->assertDownload("{$job->id}-externo.csv");
        $this->actingAs($user, 'sanctum')->deleteJson("/api/presenca/consult-jobs/{$job->id}")
            ->assertNoContent();
    }

    public function test_it_does_not_keep_a_job_when_the_external_api_fails(): void
    {
        $user = User::factory()->create();
        Http::fake(['https://apibot.test/v1/auth/login' => Http::response(['message' => 'credenciais inválidas'], 401)]);

        $this->actingAs($user, 'sanctum')->postJson('/api/presenca/consult-jobs', [
            'title' => 'Consulta externa',
            'lines' => '12345678909;Maria Silva',
        ])->assertStatus(502);

        $this->assertDatabaseMissing('presenca_consult_jobs', ['user_id' => $user->id]);
    }

    public function test_it_sends_uploaded_text_to_the_external_api(): void
    {
        $user = User::factory()->create();

        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/v1/auth/login') {
                return Http::response(['token' => 'external-token', 'expires_at' => now()->addHour()->toIso8601String()], 200);
            }

            if ($path === '/v1/jobs' && $request->method() === 'POST') {
                $this->assertSame("12345678909;Maria Silva\n98765432100;João Silva", $request->body());

                return Http::response($this->remoteJob('remote-upload', 'queued'), 202);
            }

            return Http::response(['message' => 'not found'], 404);
        });

        $this->actingAs($user, 'sanctum')->post('/api/presenca/consult-jobs', [
            'title' => 'Consulta por upload',
            'file' => UploadedFile::fake()->createWithContent('presenca.txt', "12345678909;Maria Silva\n98765432100;João Silva"),
        ])->assertAccepted();
    }

    public function test_it_blocks_any_presenca_executor_while_a_job_is_active(): void
    {
        $user = User::factory()->create();
        PresencaConsultJob::create([
            'user_id' => $user->id,
            'title' => 'Em andamento',
            'executor' => 'local',
            'status' => 'em_progresso',
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/presenca/consult-jobs', [
            'title' => 'Nova consulta',
            'lines' => '12345678909;Maria Silva',
        ])->assertConflict();

        Http::assertNothingSent();
    }

    public function test_a_paused_job_does_not_block_a_new_consultation_but_cannot_resume_while_another_is_active(): void
    {
        $user = User::factory()->create();
        $pausedJob = PresencaConsultJob::create([
            'user_id' => $user->id,
            'title' => 'Pausado',
            'executor' => 'local',
            'status' => 'pausado',
        ]);
        Http::fake([
            'https://apibot.test/v1/auth/login' => Http::response(['token' => 'external-token', 'expires_at' => now()->addHour()->toIso8601String()], 200),
            'https://apibot.test/v1/jobs*' => Http::response($this->remoteJob('remote-3', 'queued'), 202),
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/presenca/consult-jobs', [
            'title' => 'Nova consulta',
            'lines' => '12345678909;Maria Silva',
        ])->assertAccepted();

        $this->actingAs($user, 'sanctum')->postJson("/api/presenca/consult-jobs/{$pausedJob->id}/resume")
            ->assertConflict()
            ->assertJsonPath('message', 'Já existe uma consulta Presença em andamento.');
    }

    public function test_a_cancelled_job_does_not_block_a_new_consultation(): void
    {
        $user = User::factory()->create();
        PresencaConsultJob::create([
            'user_id' => $user->id,
            'title' => 'Cancelado',
            'executor' => 'local',
            'status' => 'cancelado',
        ]);
        Http::fake([
            'https://apibot.test/v1/auth/login' => Http::response(['token' => 'external-token', 'expires_at' => now()->addHour()->toIso8601String()], 200),
            'https://apibot.test/v1/jobs*' => Http::response($this->remoteJob('remote-2', 'queued'), 202),
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/presenca/consult-jobs', [
            'title' => 'Nova consulta',
            'lines' => '12345678909;Maria Silva',
        ])->assertAccepted();
    }

    private function remoteJob(string $id, string $status): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'phase' => in_array($status, ['completed', 'cancelled'], true) ? null : 'phase_1',
            'total_count' => 3,
            'metrics' => [
                'phase1.success' => 1,
                'phase1.policy_declined' => 1,
                'phase1.errors' => 1,
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
