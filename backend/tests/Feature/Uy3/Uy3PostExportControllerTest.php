<?php

declare(strict_types=1);

namespace Tests\Feature\Uy3;

use App\Models\User;
use App\Models\Uy3WebhookPost;
use App\Modules\Uy3\Jobs\GenerateUy3ExportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Uy3PostExportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'uy3.export.storage.disk' => 'local',
            'uy3.export.storage.directory' => 'uy3-test-exports',
            'uy3.export.storage.filename_prefix' => 'uy3_test_export',
            'uy3.export.csv.bom' => false,
        ]);
    }

    public function test_export_includes_old_new_and_missing_types_and_keeps_optional_fields_blank(): void
    {
        Storage::fake('local');
        Bus::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Uy3WebhookPost::query()->create([
            'payload' => json_encode([
                'cpf' => '52998224725',
                'typeWebhook' => 'LEADS_CLT',
                'nomeTrabalhador' => 'Tipo Antigo',
                'status' => 'QUALIFICADO',
            ], JSON_THROW_ON_ERROR),
            'received_at' => now()->subMinutes(3),
        ]);

        Uy3WebhookPost::query()->create([
            'payload' => json_encode([
                'cpf' => '39053344705',
                'typeWebhook' => 'LEADS_CLT_V2',
                'nomeTrabalhador' => 'Tipo Novo',
                'status' => 'NOVO',
            ], JSON_THROW_ON_ERROR),
            'received_at' => now()->subMinutes(2),
        ]);

        Uy3WebhookPost::query()->create([
            'payload' => json_encode([
                'cpf' => '191',
                'nomeTrabalhador' => 'Sem Campos',
            ], JSON_THROW_ON_ERROR),
            'received_at' => now()->subMinute(),
        ]);

        $exportResponse = $this->postJson('/api/uy3/posts/export', [
            'sort' => 'id',
            'direction' => 'asc',
            'period' => 'all',
        ]);

        $exportResponse->assertAccepted();

        $token = (string) $exportResponse->json('token');

        (new GenerateUy3ExportJob($user->id, $token, [
            'sort' => 'id',
            'direction' => 'asc',
            'period' => 'all',
        ], 3600))->handle();

        $downloadResponse = $this->get("/api/uy3/posts/export/{$token}/download");
        $downloadResponse->assertOk();

        $rows = $this->parseCsv($downloadResponse->streamedContent());

        $this->assertCount(4, $rows);
        $this->assertSame('CPF', $rows[0][0]);

        $rowsByCpf = [];
        foreach (array_slice($rows, 1) as $row) {
            $rowsByCpf[$row[0]] = $row;
        }

        $this->assertArrayHasKey('52998224725', $rowsByCpf);
        $this->assertArrayHasKey('39053344705', $rowsByCpf);
        $this->assertArrayHasKey('00000000191', $rowsByCpf);
        $this->assertSame('Tipo Antigo', $rowsByCpf['52998224725'][1]);
        $this->assertSame('Tipo Novo', $rowsByCpf['39053344705'][1]);
        $this->assertSame('Sem Campos', $rowsByCpf['00000000191'][1]);
        $this->assertSame('', $rowsByCpf['00000000191'][2]);
        $this->assertSame('', $rowsByCpf['00000000191'][3]);
        $this->assertSame('', $rowsByCpf['00000000191'][4]);
    }

    /**
     * @return list<list<string|null>>
     */
    private function parseCsv(string $content): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];
        $rows = [];

        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }

            if ($index === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            }

            $rows[] = str_getcsv($line, ';', '"', '\\');
        }

        return $rows;
    }
}
