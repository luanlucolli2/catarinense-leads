<?php

declare(strict_types=1);

namespace Tests\Feature\ShortLinks;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShortLinkProxyControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'multi_consulta.base_url' => 'https://multi-consulta.test',
            'multi_consulta.email' => 'links@test.com',
            'multi_consulta.password' => 'secret',
            'multi_consulta.timeout' => 5,
            'multi_consulta.connect_timeout' => 5,
            'multi_consulta.token_skew_seconds' => 60,
        ]);
        Sanctum::actingAs(User::factory()->make());
    }

    public function test_it_logs_in_once_and_proxies_link_listing(): void
    {
        Http::fake([
            'https://multi-consulta.test/v1/auth/login' => Http::response([
                'token' => 'token-1',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]),
            'https://multi-consulta.test/v1/links*' => Http::response([
                'items' => [],
                'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0],
            ]),
        ]);

        $this->getJson('/api/links?status=all')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson('/api/links?status=all')->assertOk();

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://multi-consulta.test/v1/links?status=all'
            && $request->hasHeader('Authorization', 'Bearer token-1'));
    }

    public function test_it_refreshes_the_token_and_retries_once_after_unauthorized_upstream_response(): void
    {
        Http::fake([
            'https://multi-consulta.test/v1/auth/login' => Http::sequence()
                ->push(['token' => 'old-token', 'expires_at' => now()->addHour()->toIso8601String()])
                ->push(['token' => 'new-token', 'expires_at' => now()->addHour()->toIso8601String()]),
            'https://multi-consulta.test/v1/links*' => Http::sequence()
                ->push(['code' => 'unauthorized', 'message' => 'expired'], 401)
                ->push(['items' => [], 'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0]]),
        ]);

        $this->getJson('/api/links')->assertOk();

        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://multi-consulta.test/v1/links')
            && $request->hasHeader('Authorization', 'Bearer new-token'));
    }

    public function test_it_returns_safe_upstream_errors_and_proxies_csv_downloads(): void
    {
        Http::fake([
            'https://multi-consulta.test/v1/auth/login' => Http::response([
                'token' => 'token-1',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]),
            'https://multi-consulta.test/v1/links/link-id' => Http::response([
                'code' => 'link_deleted',
                'message' => 'Link excluído.',
                'internal' => 'não expor',
            ], 409),
            'https://multi-consulta.test/v1/links/link-id/export.csv*' => Http::response(
                "data;ip\n2026-01-01;127\n",
                200,
                ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename=cliques.csv']
            ),
        ]);

        $this->getJson('/api/links/link-id')
            ->assertStatus(409)
            ->assertExactJson(['code' => 'link_deleted', 'message' => 'Link excluído.']);

        $this->get('/api/links/link-id/export.csv?period=7d')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=cliques.csv')
            ->assertSee('data;ip');
    }

    public function test_links_proxy_requires_local_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/links')->assertUnauthorized();
    }
}
