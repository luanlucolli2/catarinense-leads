<?php

namespace Tests\Feature\SomaClt;

use App\Models\User;
use App\Modules\SomaClt\Jobs\StoreSomaCltExternalReportJob;
use App\Modules\SomaClt\Models\SomaCltConsultJob;
use App\Modules\SomaClt\Services\SomaCltExternalApiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SomaCltExternalApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('multi_consulta:short_links:token');
        config(['multi_consulta.base_url' => 'https://apibot.test', 'multi_consulta.email' => 'user@test.com', 'multi_consulta.password' => 'secret']);
    }

    public function test_it_creates_syncs_controls_and_persists_the_external_report(): void
    {
        $user = User::factory()->create();
        $status = 'scheduled';
        Queue::fake();

        Http::fake(function ($request) use (&$status) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if ($path === '/v1/auth/login') return Http::response(['token' => 'token', 'expires_at' => now()->addHour()->toIso8601String()]);
            if ($path === '/v1/jobs' && $request->method() === 'POST') {
                $this->assertSame('soma_clt', $query['module'] ?? null);
                $this->assertSame('uy3', $query['mode'] ?? null);
                $this->assertSame('2030-01-01T13:00:00+00:00', $query['scheduled_for'] ?? null);
                $this->assertSame("00700367136;RICARDO MENDES FIGUEIRA\n00021143480;", $request->body());
                return Http::response($this->remote('soma-1', $status), 202);
            }
            if ($path === '/v1/jobs/soma-1' && $request->method() === 'GET') { $status = 'running'; return Http::response($this->remote('soma-1', $status)); }
            if ($path === '/v1/jobs/soma-1/pause') { $status = 'paused'; return Http::response($this->remote('soma-1', $status), 202); }
            if ($path === '/v1/jobs/soma-1/resume') { $status = 'queued'; return Http::response($this->remote('soma-1', $status), 202); }
            if ($path === '/v1/jobs/soma-1/cancel') { $status = 'cancelled'; return Http::response($this->remote('soma-1', $status, true), 202); }
            if ($path === '/v1/jobs/soma-1/preview') return Http::response("CPF;Status\n12345678909;SUCESSO\n", 200, ['Content-Disposition' => 'attachment; filename="previa.csv"']);
            if ($path === '/v1/jobs/soma-1/report') return Http::response("CPF;Status\n12345678909;SUCESSO\n", 200, ['Content-Disposition' => 'attachment; filename="final.csv"']);
            if ($path === '/v1/jobs/soma-1' && $request->method() === 'DELETE') return Http::response(null, 204);
            return Http::response(['message' => 'not found'], 404);
        });

        $this->actingAs($user, 'sanctum')->postJson('/api/soma-clt/consult-jobs', [
            'title' => 'Consulta Soma', 'mode' => 'uy3', 'lines' => "SEM CPF\n700367136\tRICARDO MENDES FIGUEIRA\n21143480;",
            'run_at' => '2030-01-01T10:00', 'timezone' => 'America/Sao_Paulo',
        ])->assertAccepted()->assertJsonPath('status', 'agendado');

        $job = SomaCltConsultJob::query()->firstOrFail();
        $this->actingAs($user, 'sanctum')->getJson("/api/soma-clt/consult-jobs/{$job->id}")
            ->assertOk()->assertJsonPath('status', 'em_progresso')->assertJsonPath('success_count', 2)
            ->assertJsonPath('policy_declined_count', 3)->assertJsonPath('fail_count', 4);
        $this->actingAs($user, 'sanctum')->postJson("/api/soma-clt/consult-jobs/{$job->id}/pause")->assertAccepted()->assertJsonPath('status', 'pausado');
        $this->actingAs($user, 'sanctum')->postJson("/api/soma-clt/consult-jobs/{$job->id}/resume")->assertAccepted()->assertJsonPath('status', 'pendente');
        $this->actingAs($user, 'sanctum')->get("/api/soma-clt/consult-jobs/{$job->id}/preview")->assertOk()->assertDownload('previa.csv');
        $this->actingAs($user, 'sanctum')->postJson("/api/soma-clt/consult-jobs/{$job->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelado');
        Queue::assertPushed(StoreSomaCltExternalReportJob::class, fn ($queued) => $queued->jobId === $job->id);
        (new StoreSomaCltExternalReportJob($job->id))->handle(app(SomaCltExternalApiService::class));
        $this->actingAs($user, 'sanctum')->get("/api/soma-clt/consult-jobs/{$job->id}/download")->assertOk()->assertDownload("{$job->id}-final.csv");
        $this->actingAs($user, 'sanctum')->deleteJson("/api/soma-clt/consult-jobs/{$job->id}")->assertNoContent();
    }

    public function test_it_does_not_persist_on_external_failure_and_validates_mode(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/soma-clt/consult-jobs', ['title' => 'X', 'mode' => 'invalido', 'lines' => '12345678909;MARIA'])->assertUnprocessable();
        Http::fake(['https://apibot.test/v1/auth/login' => Http::response(['message' => 'inválido'], 401)]);
        $this->actingAs($user, 'sanctum')->postJson('/api/soma-clt/consult-jobs', ['title' => 'X', 'mode' => 'celcoin', 'lines' => '12345678909;MARIA'])->assertStatus(502);
        $this->assertDatabaseMissing('soma_clt_consult_jobs', ['user_id' => $user->id]);
    }

    public function test_paused_does_not_block_creation_but_cannot_resume_while_another_job_is_active(): void
    {
        $user = User::factory()->create();
        $paused = SomaCltConsultJob::create(['user_id' => $user->id, 'title' => 'Pausado', 'mode' => 'uy3', 'external_job_id' => 'paused', 'status' => 'pausado']);
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/auth/login') return Http::response(['token' => 'token', 'expires_at' => now()->addHour()->toIso8601String()]);
            if ($path === '/v1/jobs/paused') return Http::response($this->remote('paused', 'paused'));
            if ($path === '/v1/jobs' && $request->method() === 'POST') return Http::response($this->remote('new', 'queued'), 202);
            return Http::response(['message' => 'not found'], 404);
        });
        $this->actingAs($user, 'sanctum')->postJson('/api/soma-clt/consult-jobs', ['title' => 'Novo', 'mode' => 'uy3', 'lines' => '12345678909;MARIA'])->assertAccepted();
        SomaCltConsultJob::create(['user_id' => $user->id, 'title' => 'Ativo', 'mode' => 'uy3', 'external_job_id' => 'active', 'status' => 'em_progresso']);
        $this->actingAs($user, 'sanctum')->postJson("/api/soma-clt/consult-jobs/{$paused->id}/resume")->assertConflict();
    }

    private function remote(string $id, string $status, bool $hasReport = false): array
    {
        return ['id' => $id, 'status' => $status, 'phase' => 'phase_1', 'total_count' => 9, 'has_report' => $hasReport,
            'metrics' => ['phase1.success' => 2, 'phase1.declined' => 3, 'phase1.errors' => 4]];
    }
}
