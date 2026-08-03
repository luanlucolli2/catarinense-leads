<?php

namespace Tests\Feature\V8Fgts;

use App\Models\User;
use App\Modules\V8Fgts\Models\V8FgtsConsultJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class V8FgtsExternalApiExecutorTest extends TestCase
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

    public function test_it_creates_tracks_cancels_and_downloads_an_external_job(): void
    {
        $user = User::factory()->create();
        $remoteStatus = 'queued';

        Http::fake(function ($request) use (&$remoteStatus) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/v1/auth/login') {
                return Http::response(['token' => 'external-token', 'expires_at' => now()->addHour()->toIso8601String()], 200);
            }

            if ($path === '/v1/jobs' && $request->method() === 'POST') {
                $this->assertSame("12345678909\n98765432100", $request->body());

                return Http::response($this->remoteJob('remote-1', $remoteStatus), 202);
            }

            if ($path === '/v1/jobs/remote-1' && $request->method() === 'GET') {
                $remoteStatus = 'running';

                return Http::response($this->remoteJob('remote-1', $remoteStatus), 200);
            }

            if ($path === '/v1/jobs/remote-1/cancel') {
                $remoteStatus = 'cancelled';

                return Http::response($this->remoteJob('remote-1', $remoteStatus), 202);
            }

            if ($path === '/v1/jobs/remote-1/preview') {
                return Http::response("CPF;Status\n12345678909;SUCESSO\n", 200, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="previa.csv"',
                ]);
            }

            if ($path === '/v1/jobs/remote-1/report') {
                return Http::response("CPF;Status\n12345678909;SUCESSO\n", 200, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="externo.csv"',
                ]);
            }

            if ($path === '/v1/jobs/remote-1' && $request->method() === 'DELETE') {
                return Http::response(null, 204);
            }

            return Http::response(['message' => 'not found'], 404);
        });

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v8-fgts/consult-jobs', [
            'title' => 'Consulta externa',
            'cpfs' => "12345678909\n98765432100",
            'executor' => 'api',
        ]);

        $created->assertAccepted();
        $job = V8FgtsConsultJob::query()->firstOrFail();
        $this->assertSame('api', $job->executor);
        $this->assertSame('remote-1', $job->external_job_id);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v8-fgts/consult-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('status', 'em_progresso');

        $this->actingAs($user, 'sanctum')
            ->get("/api/v8-fgts/consult-jobs/{$job->id}/preview")
            ->assertOk()
            ->assertDownload('previa.csv');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v8-fgts/consult-jobs/{$job->id}/cancel", ['reason' => 'teste'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelado');

        $this->actingAs($user, 'sanctum')
            ->get("/api/v8-fgts/consult-jobs/{$job->id}/download")
            ->assertOk()
            ->assertDownload('externo.csv');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v8-fgts/consult-jobs/{$job->id}")
            ->assertNoContent();
        $this->assertDatabaseMissing('v8_fgts_consult_jobs', ['id' => $job->id]);
    }

    public function test_it_does_not_keep_a_job_when_the_external_api_fails(): void
    {
        $user = User::factory()->create();
        Http::fake(['https://apibot.test/v1/auth/login' => Http::response(['message' => 'credenciais inválidas'], 401)]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v8-fgts/consult-jobs', [
                'title' => 'Consulta externa',
                'cpfs' => '12345678909',
                'executor' => 'api',
            ])
            ->assertStatus(502);

        $this->assertDatabaseCount('v8_fgts_consult_jobs', 0);
    }

    public function test_it_blocks_any_executor_while_a_v8_fgts_job_is_active(): void
    {
        $user = User::factory()->create();
        V8FgtsConsultJob::create([
            'user_id' => $user->id,
            'title' => 'Em andamento',
            'executor' => 'local',
            'status' => 'em_progresso',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v8-fgts/consult-jobs', [
                'title' => 'Nova consulta',
                'cpfs' => '12345678909',
                'executor' => 'api',
            ])
            ->assertConflict();

        Http::assertNothingSent();
    }

    public function test_a_cancelled_job_does_not_block_a_new_consultation(): void
    {
        $user = User::factory()->create();
        V8FgtsConsultJob::create([
            'user_id' => $user->id,
            'title' => 'Cancelado',
            'executor' => 'local',
            'status' => 'cancelado',
        ]);
        Http::fake([
            'https://apibot.test/v1/auth/login' => Http::response(['token' => 'external-token', 'expires_at' => now()->addHour()->toIso8601String()], 200),
            'https://apibot.test/v1/jobs*' => Http::response($this->remoteJob('remote-2', 'queued'), 202),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v8-fgts/consult-jobs', [
                'title' => 'Nova consulta',
                'cpfs' => '12345678909',
                'executor' => 'api',
            ])
            ->assertAccepted();
    }

    private function remoteJob(string $id, string $status): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'phase' => 'phase_1',
            'total_count' => 2,
            'metrics' => [
                'pipeline.success' => 1,
                'pipeline.not_eligible' => 0,
                'pipeline.errors' => 0,
            ],
            'has_report' => $status === 'cancelled',
            'started_at' => '2026-07-30T12:00:00Z',
            'finished_at' => $status === 'cancelled' ? '2026-07-30T12:10:00Z' : null,
            'canceled_at' => $status === 'cancelled' ? '2026-07-30T12:10:00Z' : null,
        ];
    }
}
