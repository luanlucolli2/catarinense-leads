<?php

namespace Tests\Feature\V8Fgts;

use App\Models\User;
use App\Modules\V8Fgts\Jobs\ProcessV8FgtsConsultJob;
use App\Modules\V8Fgts\Models\V8FgtsConsultJob;
use App\Modules\V8Fgts\Support\V8FgtsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessV8FgtsConsultJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_a_happy_path_job_until_simulation_with_paginated_scan(): void
    {
        Storage::fake('v8-fgts-test');
        Carbon::setTestNow('2026-06-03 10:00:00');

        config([
            'v8.oauth.base_url' => 'https://auth.v8.test',
            'v8.oauth.username' => 'user',
            'v8.oauth.password' => 'pass',
            'v8.oauth.audience' => 'aud',
            'v8.oauth.client_id' => 'client',
            'v8_fgts.storage.reports_disk' => 'v8-fgts-test',
            'v8_fgts.bff.base_url' => 'https://bff.v8.test',
            'v8_fgts.job.start_retry_delay_seconds' => 0,
            'v8_fgts.job.polling_round_delay_seconds' => 0,
            'v8_fgts.job.phase2_page_limit' => 1,
        ]);

        Http::fake([
            'https://auth.v8.test/oauth/token' => Http::response([
                'access_token' => 'shared-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'https://bff.v8.test/fgts/balance*' => function ($request) {
                if ($request->method() === 'POST') {
                    return Http::response(null, 200);
                }

                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                return Http::response([
                    'data' => $page === 2 ? [[
                        'id' => 'BAL-123',
                        'documentNumber' => '12345678909',
                        'status' => 'success',
                        'statusInfo' => null,
                        'createdAt' => '2026-06-03T10:00:02Z',
                        'updatedAt' => '2026-06-03T10:00:02Z',
                        'amount' => 304.66,
                        'provider' => 'bms',
                        'periods' => [
                            ['amount' => 180.16, 'dueDate' => '2030-06-01'],
                            ['amount' => 124.50, 'dueDate' => '2031-06-01'],
                        ],
                    ]] : [],
                    'pages' => [
                        'limit' => 1,
                        'total' => 1,
                        'current' => $page,
                        'hasNext' => $page < 2,
                        'hasPrev' => $page > 1,
                        'totalPages' => 2,
                    ],
                ], 200);
            },
            'https://bff.v8.test/fgts/simulations/fees' => Http::response([
                [
                    'active' => true,
                    'simulation_fees' => [
                        'label' => 'normal',
                        'id_simulation_fees' => 'FEE-123',
                    ],
                ],
            ], 200),
            'https://bff.v8.test/fgts/simulations' => Http::response([
                'id' => 'SIM-123',
                'availableBalance' => 85.45,
                'emissionAmount' => 117.67,
                'totalBalance' => 304.66,
                'totalInstallments' => 2,
                'tax' => 1.8,
                'cet' => 0.0242,
                'annualCet' => 33.23,
                'iof' => 3.98,
                'tc' => 24,
            ], 200),
        ]);

        $job = $this->makePendingJob();

        ProcessV8FgtsConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(1, $job->success_count);
        $this->assertNotNull($job->file_path);
        $this->assertTrue(Storage::disk('v8-fgts-test')->exists($job->file_path));
        $this->assertStringContainsString('SIM-123', Storage::disk('v8-fgts-test')->get($job->file_path));

        $requests = Http::recorded();
        $this->assertTrue(collect($requests)->contains(function (array $pair) {
            $request = $pair[0];
            return $request->method() === 'GET'
                && str_contains($request->url(), '/fgts/balance?')
                && str_contains($request->url(), 'startDate=')
                && !str_contains($request->url(), 'search=');
        }));
        $this->assertFalse(collect($requests)->contains(function (array $pair) {
            $request = $pair[0];
            return $request->method() === 'GET'
                && str_contains($request->url(), '/fgts/balance?')
                && str_contains($request->url(), 'search=');
        }));

        Carbon::setTestNow();
    }

    public function test_it_uses_search_fallback_with_date_window_when_paginated_scan_misses(): void
    {
        Storage::fake('v8-fgts-test');
        Carbon::setTestNow('2026-06-03 10:00:00');

        config([
            'v8.oauth.base_url' => 'https://auth.v8.test',
            'v8.oauth.username' => 'user',
            'v8.oauth.password' => 'pass',
            'v8.oauth.audience' => 'aud',
            'v8.oauth.client_id' => 'client',
            'v8_fgts.storage.reports_disk' => 'v8-fgts-test',
            'v8_fgts.bff.base_url' => 'https://bff.v8.test',
            'v8_fgts.job.start_retry_delay_seconds' => 0,
            'v8_fgts.job.polling_round_delay_seconds' => 0,
            'v8_fgts.job.phase2_plain_search_last_resort_round_start' => 99,
        ]);

        Http::fake([
            'https://auth.v8.test/oauth/token' => Http::response([
                'access_token' => 'shared-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'https://bff.v8.test/fgts/balance*' => function ($request) {
                if ($request->method() === 'POST') {
                    return Http::response(null, 200);
                }

                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $hasSearch = isset($query['search']) && $query['search'] !== '';
                $hasDate = isset($query['startDate']) && isset($query['endDate']);

                if ($hasSearch && $hasDate) {
                    return Http::response([
                        'data' => [[
                            'id' => 'BAL-SEARCH',
                            'documentNumber' => '12345678909',
                            'status' => 'success',
                            'statusInfo' => null,
                            'createdAt' => '2026-06-03T10:00:03Z',
                            'updatedAt' => '2026-06-03T10:00:03Z',
                            'amount' => 304.66,
                            'provider' => 'bms',
                            'periods' => [
                                ['amount' => 180.16, 'dueDate' => '2030-06-01'],
                                ['amount' => 124.50, 'dueDate' => '2031-06-01'],
                            ],
                        ]],
                        'pages' => [
                            'limit' => 50,
                            'total' => 1,
                            'current' => 1,
                            'hasNext' => false,
                            'hasPrev' => false,
                            'totalPages' => 1,
                        ],
                    ], 200);
                }

                return Http::response([
                    'data' => [],
                    'pages' => [
                        'limit' => 100,
                        'total' => 0,
                        'current' => 1,
                        'hasNext' => false,
                        'hasPrev' => false,
                        'totalPages' => 1,
                    ],
                ], 200);
            },
            'https://bff.v8.test/fgts/simulations/fees' => Http::response([
                [
                    'active' => true,
                    'simulation_fees' => [
                        'label' => 'normal',
                        'id_simulation_fees' => 'FEE-123',
                    ],
                ],
            ], 200),
            'https://bff.v8.test/fgts/simulations' => Http::response([
                'id' => 'SIM-123',
                'availableBalance' => 85.45,
                'emissionAmount' => 117.67,
                'totalBalance' => 304.66,
                'totalInstallments' => 2,
                'tax' => 1.8,
                'cet' => 0.0242,
                'annualCet' => 33.23,
                'iof' => 3.98,
                'tc' => 24,
            ], 200),
        ]);

        $job = $this->makePendingJob();

        ProcessV8FgtsConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(1, $job->success_count);
        $this->assertNotNull($job->file_path);
        $this->assertTrue(Storage::disk('v8-fgts-test')->exists($job->file_path));

        $requests = Http::recorded();
        $this->assertTrue(collect($requests)->contains(function (array $pair) {
            $request = $pair[0];
            return $request->method() === 'GET'
                && str_contains($request->url(), '/fgts/balance?')
                && str_contains($request->url(), 'search=12345678909')
                && str_contains($request->url(), 'startDate=');
        }));

        Carbon::setTestNow();
    }

    public function test_it_uses_plain_search_last_resort_when_dated_search_does_not_find_match(): void
    {
        Storage::fake('v8-fgts-test');
        Carbon::setTestNow('2026-06-03 10:00:00');

        config([
            'v8.oauth.base_url' => 'https://auth.v8.test',
            'v8.oauth.username' => 'user',
            'v8.oauth.password' => 'pass',
            'v8.oauth.audience' => 'aud',
            'v8.oauth.client_id' => 'client',
            'v8_fgts.storage.reports_disk' => 'v8-fgts-test',
            'v8_fgts.bff.base_url' => 'https://bff.v8.test',
            'v8_fgts.job.start_retry_delay_seconds' => 0,
            'v8_fgts.job.polling_round_delay_seconds' => 0,
            'v8_fgts.job.phase2_plain_search_last_resort_round_start' => 1,
        ]);

        Http::fake([
            'https://auth.v8.test/oauth/token' => Http::response([
                'access_token' => 'shared-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'https://bff.v8.test/fgts/balance*' => function ($request) {
                if ($request->method() === 'POST') {
                    return Http::response(null, 200);
                }

                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $hasSearch = isset($query['search']) && $query['search'] !== '';
                $hasDate = isset($query['startDate']) && isset($query['endDate']);

                if ($hasSearch && !$hasDate) {
                    return Http::response([
                        'data' => [[
                            'id' => 'BAL-PLAIN',
                            'documentNumber' => '12345678909',
                            'status' => 'success',
                            'statusInfo' => null,
                            'createdAt' => '2026-06-03T10:00:04Z',
                            'updatedAt' => '2026-06-03T10:00:04Z',
                            'amount' => 304.66,
                            'provider' => 'bms',
                            'periods' => [
                                ['amount' => 180.16, 'dueDate' => '2030-06-01'],
                                ['amount' => 124.50, 'dueDate' => '2031-06-01'],
                            ],
                        ]],
                        'pages' => [
                            'limit' => 50,
                            'total' => 1,
                            'current' => 1,
                            'hasNext' => false,
                            'hasPrev' => false,
                            'totalPages' => 1,
                        ],
                    ], 200);
                }

                return Http::response([
                    'data' => [],
                    'pages' => [
                        'limit' => 50,
                        'total' => 0,
                        'current' => 1,
                        'hasNext' => false,
                        'hasPrev' => false,
                        'totalPages' => 1,
                    ],
                ], 200);
            },
            'https://bff.v8.test/fgts/simulations/fees' => Http::response([
                [
                    'active' => true,
                    'simulation_fees' => [
                        'label' => 'normal',
                        'id_simulation_fees' => 'FEE-123',
                    ],
                ],
            ], 200),
            'https://bff.v8.test/fgts/simulations' => Http::response([
                'id' => 'SIM-123',
                'availableBalance' => 85.45,
                'emissionAmount' => 117.67,
                'totalBalance' => 304.66,
                'totalInstallments' => 2,
                'tax' => 1.8,
                'cet' => 0.0242,
                'annualCet' => 33.23,
                'iof' => 3.98,
                'tc' => 24,
            ], 200),
        ]);

        $job = $this->makePendingJob();

        ProcessV8FgtsConsultJob::dispatchSync($job->id);

        $job->refresh();

        $this->assertSame('concluido', $job->status);
        $this->assertSame(1, $job->success_count);
        $this->assertNotNull($job->file_path);
        $this->assertTrue(Storage::disk('v8-fgts-test')->exists($job->file_path));

        $requests = Http::recorded();
        $this->assertTrue(collect($requests)->contains(function (array $pair) {
            $request = $pair[0];
            return $request->method() === 'GET'
                && str_contains($request->url(), '/fgts/balance?')
                && str_contains($request->url(), 'search=12345678909')
                && !str_contains($request->url(), 'startDate=');
        }));

        Carbon::setTestNow();
    }

    private function makePendingJob(): V8FgtsConsultJob
    {
        $user = User::factory()->create();
        $job = V8FgtsConsultJob::create([
            'user_id' => $user->id,
            'title' => 'Teste FGTS V8',
            'status' => 'pendente',
        ]);

        $spoolPath = 'v8-fgts-spool/teste.spool.csv';
        $cpfsPath = 'v8-fgts-spool/teste.cpfs.txt';
        Storage::disk('v8-fgts-test')->put($spoolPath, implode(';', V8FgtsSchema::TITLES) . "\n");
        Storage::disk('v8-fgts-test')->put($cpfsPath, "12345678909\n");
        $job->update([
            'spool_path' => $spoolPath,
            'spool_cpfs_path' => $cpfsPath,
        ]);

        return $job;
    }
}
