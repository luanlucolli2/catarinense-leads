<?php

declare(strict_types=1);

namespace Tests\Feature\DisparosWhatsappVendeai;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailingInboxesControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set([
            'vendeai.mailing.base_url' => 'https://vendeai.test',
            'vendeai.mailing.account_id' => 'account-test',
            'vendeai.mailing.crm_api_access_token' => 'token-test',
            'vendeai.mailing.timeout_seconds' => 5,
            'vendeai.mailing.inboxes_cache_seconds' => 300,
        ]);
    }

    public function test_inboxes_require_authentication(): void
    {
        $this->getJson('/api/disparos-whatsapp-vendeai/inboxes')->assertUnauthorized();
    }

    public function test_inboxes_return_whatsapp_inboxes_and_all_template_statuses(): void
    {
        $this->authenticate();
        Http::fake(['https://vendeai.test/*' => Http::response(['inboxes' => [
            [
                'id' => '1', 'name' => 'WhatsApp oficial', 'channel' => 'whatsapp', 'phone_number' => '+5547999999999',
                'templates' => [
                    ['id' => 'approved', 'name' => 'aviso', 'status' => 'APPROVED', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}', 'variables' => ['1'], 'header_type' => 'IMAGE', 'header_variables' => [], 'header_text' => null],
                    ['id' => 'paused', 'name' => 'pausado', 'status' => 'PAUSED'],
                ],
            ],
            ['id' => '2', 'name' => 'Sem aprovados', 'channel' => 'whatsapp', 'phone_number' => '+5547888888888', 'templates' => [['id' => 'paused', 'name' => 'pausado', 'status' => 'PAUSED']]],
            ['id' => '3', 'name' => 'Outro canal', 'channel' => 'instagram', 'phone_number' => '+5547777777777', 'templates' => [['id' => 'approved', 'name' => 'aviso', 'status' => 'APPROVED']]],
            ['id' => '4', 'name' => 'Sem templates', 'channel' => 'whatsapp', 'phone_number' => '+5547666666666', 'templates' => []],
        ]])]);

        $this->getJson('/api/disparos-whatsapp-vendeai/inboxes')
            ->assertOk()
            ->assertExactJson(['inboxes' => [
                ['id' => '1', 'name' => 'WhatsApp oficial', 'phone_number' => '+5547999999999', 'templates' => [
                    ['id' => 'approved', 'name' => 'aviso', 'status' => 'APPROVED', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}', 'variables' => ['1'], 'header_type' => 'IMAGE', 'header_variables' => [], 'header_text' => null],
                    ['id' => 'paused', 'name' => 'pausado', 'status' => 'PAUSED', 'category' => '', 'language' => '', 'body' => '', 'variables' => [], 'header_type' => null, 'header_variables' => [], 'header_text' => null],
                ]],
                ['id' => '2', 'name' => 'Sem aprovados', 'phone_number' => '+5547888888888', 'templates' => [['id' => 'paused', 'name' => 'pausado', 'status' => 'PAUSED', 'category' => '', 'language' => '', 'body' => '', 'variables' => [], 'header_type' => null, 'header_variables' => [], 'header_text' => null]]],
                ['id' => '4', 'name' => 'Sem templates', 'phone_number' => '+5547666666666', 'templates' => []],
            ]]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://vendeai.test/api/message-handler/mailing/inboxes/'
            && $request['account_id'] === 'account-test'
            && $request['crm_api_access_token'] === 'token-test');
    }

    public function test_inboxes_cache_response_and_refresh_bypasses_cache(): void
    {
        $this->authenticate();
        Http::fake(['https://vendeai.test/*' => Http::response(['inboxes' => []])]);

        $this->getJson('/api/disparos-whatsapp-vendeai/inboxes')->assertOk();
        $this->getJson('/api/disparos-whatsapp-vendeai/inboxes')->assertOk();
        Http::assertSentCount(1);

        $this->getJson('/api/disparos-whatsapp-vendeai/inboxes?refresh=1')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_inboxes_report_missing_configuration_without_exposing_credentials(): void
    {
        $this->authenticate();
        config()->set('vendeai.mailing.crm_api_access_token', '');

        $this->getJson('/api/disparos-whatsapp-vendeai/inboxes')
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Integração de mailing VendeAI não configurada.'])
            ->assertDontSee('token-test');
    }

    public function test_inboxes_handle_remote_failures_and_invalid_responses(): void
    {
        $this->authenticate();
        Http::fake(['https://vendeai.test/*' => Http::response(['error' => 'token-test'], 500)]);

        $this->getJson('/api/disparos-whatsapp-vendeai/inboxes')
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Não foi possível carregar inboxes e templates da VendeAI. Tente novamente.'])
            ->assertDontSee('token-test');

        Http::fake(['https://vendeai.test/*' => Http::response(['unexpected' => []])]);
        $this->getJson('/api/disparos-whatsapp-vendeai/inboxes?refresh=1')->assertStatus(502);
    }

    public function test_inboxes_handle_connection_failures(): void
    {
        $this->authenticate();
        Http::fake(['https://vendeai.test/*' => Http::failedConnection()]);

        $this->getJson('/api/disparos-whatsapp-vendeai/inboxes')->assertStatus(502);
    }

    private function authenticate(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }
}
