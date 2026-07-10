<?php

namespace Tests\Feature\HubCredito;

use App\Modules\HubCredito\Services\HubCreditoSharedAuthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubCreditoSharedAuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'hubcredito.auth.base_url' => 'https://api.hubcredito.test',
            'hubcredito.auth.username' => 'user@test',
            'hubcredito.auth.password' => 'secret',
            'hubcredito.http.timeout' => 5,
            'hubcredito.http.connect_timeout' => 5,
            'hubcredito.http.retry' => 0,
            'hubcredito.http.min_interval_ms' => 0,
        ]);
    }

    public function test_it_logs_in_once_and_reuses_cached_token(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response([
                'value' => [
                    'id' => 'user-id-1',
                    'token' => [
                        'accessToken' => 'token-1',
                        'refreshToken' => 'refresh-1',
                        'expiration' => '2026-07-08 11:00:00',
                    ],
                ],
            ], 200),
        ]);

        $service = new HubCreditoSharedAuthService();

        $this->assertSame('token-1', $service->getAccessToken());
        $this->assertSame('token-1', $service->getAccessToken());
        $this->assertCount(1, Http::recorded());

        Carbon::setTestNow();
    }

    public function test_it_refreshes_expired_cached_token(): void
    {
        Carbon::setTestNow('2026-07-08 10:00:00');

        Cache::put('hubcredito_auth_payload', [
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-1',
            'user_id' => 'user-id-1',
            'expires_at' => Carbon::now()->subMinute()->getTimestamp(),
        ], 3600);

        Http::fake([
            'https://api.hubcredito.test/api/Login' => Http::response([
                'value' => [
                    'id' => 'user-id-1',
                    'token' => [
                        'accessToken' => 'token-refreshed',
                        'refreshToken' => 'refresh-2',
                        'expiration' => '2026-07-08 12:00:00',
                    ],
                ],
            ], 200),
        ]);

        $service = new HubCreditoSharedAuthService();

        $this->assertSame('token-refreshed', $service->getAccessToken());
        $requests = Http::recorded();
        $this->assertCount(1, $requests);
        $this->assertSame('refresh_token', $requests[0][0]->data()['grantTypes']);

        Carbon::setTestNow();
    }
}
