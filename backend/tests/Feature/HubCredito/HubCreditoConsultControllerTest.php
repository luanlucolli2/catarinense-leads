<?php

namespace Tests\Feature\HubCredito;

use App\Models\User;
use App\Modules\HubCredito\Jobs\DispatchHubCreditoConsultJob;
use App\Modules\HubCredito\Models\HubCreditoConsultJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HubCreditoConsultControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('hubcredito-test');
        config([
            'hubcredito.storage.reports_disk' => 'hubcredito-test',
        ]);
    }

    public function test_store_accepts_valid_payload_and_dispatches_job(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/hubcredito-clt/consult-jobs', [
            'title' => 'Hubcredito teste',
            'lines' => [
                '12345678909 Fulano da Silva 01/02/1990',
            ],
        ]);

        $response->assertAccepted();
        $this->assertDatabaseCount('hubcredito_consult_jobs', 1);

        /** @var HubCreditoConsultJob $job */
        $job = HubCreditoConsultJob::query()->firstOrFail();
        $this->assertTrue(Storage::disk('hubcredito-test')->exists((string) $job->spool_path));
        $this->assertTrue(Storage::disk('hubcredito-test')->exists((string) $job->spool_inputs_path));

        Queue::assertPushed(DispatchHubCreditoConsultJob::class);
    }

    public function test_store_rejects_invalid_payload(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/hubcredito-clt/consult-jobs', [
            'lines' => [
                '12345678909 Fulano da Silva 01/02/1990',
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('hubcredito_consult_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_store_rejects_empty_payload_after_spool_creation(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/hubcredito-clt/consult-jobs', [
            'title' => 'Hubcredito vazio',
            'lines' => [],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('hubcredito_consult_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_store_accepts_duplicate_cpfs_in_payload(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/hubcredito-clt/consult-jobs', [
            'title' => 'Hubcredito duplicado',
            'lines' => [
                '12345678909 Fulano da Silva 01/02/1990',
                '12345678909 Fulano da Silva 01/02/1990',
            ],
        ]);

        $response->assertAccepted();
        $this->assertDatabaseCount('hubcredito_consult_jobs', 1);
        Queue::assertPushed(DispatchHubCreditoConsultJob::class);
    }
}
