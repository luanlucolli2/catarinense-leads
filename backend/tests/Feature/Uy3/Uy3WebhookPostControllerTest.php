<?php

declare(strict_types=1);

namespace Tests\Feature\Uy3;

use App\Models\Uy3WebhookPost;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class Uy3WebhookPostControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['uy3.webhook_secret' => 'uy3-test-secret']);
    }

    public function test_accepts_payload_with_new_type_and_normalizes_cpf(): void
    {
        $response = $this
            ->withHeaders(['Secret-Key' => 'uy3-test-secret'])
            ->postJson('/api/webhooks/uy3/posts', [
                'cpf' => '191',
                'typeWebhook' => 'LEADS_CLT_V2',
                'nomeTrabalhador' => 'Novo Tipo',
            ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $saved = Uy3WebhookPost::query()->firstOrFail();
        $payload = json_decode((string) $saved->payload, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'cpf' => '00000000191',
            'typeWebhook' => 'LEADS_CLT_V2',
            'nomeTrabalhador' => 'Novo Tipo',
        ], $payload);
    }

    public function test_accepts_payload_without_type(): void
    {
        $response = $this
            ->withHeaders(['Secret-Key' => 'uy3-test-secret'])
            ->postJson('/api/webhooks/uy3/posts', [
                'cpf' => '52998224725',
                'nomeTrabalhador' => 'Sem Tipo',
            ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $saved = Uy3WebhookPost::query()->firstOrFail();
        $payload = json_decode((string) $saved->payload, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'cpf' => '52998224725',
            'nomeTrabalhador' => 'Sem Tipo',
        ], $payload);
    }

    public function test_rejects_empty_body(): void
    {
        $response = $this
            ->withHeaders([
                'Secret-Key' => 'uy3-test-secret',
                'Content-Type' => 'application/json',
            ])
            ->call('POST', '/api/webhooks/uy3/posts', [], [], [], [], '');

        $response->assertStatus(422)->assertJson([
            'error' => 'invalid_payload',
            'message' => 'Empty JSON payload.',
        ]);
    }

    public function test_rejects_json_list(): void
    {
        $response = $this
            ->withHeaders(['Secret-Key' => 'uy3-test-secret'])
            ->postJson('/api/webhooks/uy3/posts', ['a', 'b']);

        $response->assertStatus(422)->assertJson([
            'error' => 'validation_error',
            'message' => 'Invalid webhook payload.',
        ]);
    }

    public function test_rejects_missing_cpf(): void
    {
        $response = $this
            ->withHeaders(['Secret-Key' => 'uy3-test-secret'])
            ->postJson('/api/webhooks/uy3/posts', [
                'nomeTrabalhador' => 'Sem CPF',
            ]);

        $response->assertStatus(422)->assertJson([
            'error' => 'validation_error',
            'message' => 'Invalid webhook payload.',
            'errors' => [
                'cpf' => ['CPF invalido ou ausente.'],
            ],
        ]);
    }

    public function test_rejects_cpf_with_more_than_eleven_digits(): void
    {
        $response = $this
            ->withHeaders(['Secret-Key' => 'uy3-test-secret'])
            ->postJson('/api/webhooks/uy3/posts', [
                'cpf' => '123456789012',
            ]);

        $response->assertStatus(422)->assertJsonPath('errors.cpf.0', 'CPF invalido ou ausente.');
    }

    public function test_rejects_cpf_without_digits(): void
    {
        $response = $this
            ->withHeaders(['Secret-Key' => 'uy3-test-secret'])
            ->postJson('/api/webhooks/uy3/posts', [
                'cpf' => 'abc',
            ]);

        $response->assertStatus(422)->assertJsonPath('errors.cpf.0', 'CPF invalido ou ausente.');
    }

    public function test_rejects_invalid_cpf_after_normalization(): void
    {
        $response = $this
            ->withHeaders(['Secret-Key' => 'uy3-test-secret'])
            ->postJson('/api/webhooks/uy3/posts', [
                'cpf' => '123',
            ]);

        $response->assertStatus(422)->assertJsonPath('errors.cpf.0', 'CPF invalido ou ausente.');
    }
}
