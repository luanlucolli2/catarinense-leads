<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\ImportJob;
use App\Jobs\ProcessLeadImportJob;
use Tests\TestCase;

class ImportEndpointTest extends TestCase
{
    // Este Trait garante que o banco de dados seja zerado antes de cada teste.
    // É como rodar migrate:fresh automaticamente.
    use RefreshDatabase;

    /**
     * Testa o upload de uma planilha de cadastro.
     */
    public function test_it_can_accept_a_cadastral_import_file(): void
    {
        // 1. Prepara o ambiente do teste
        Queue::fake(); // Impede que os Jobs sejam realmente executados, apenas "finge" que foram para a fila
        Storage::fake('local'); // Usa um sistema de arquivos falso em memória

        $user = User::factory()->create(); // Cria um usuário de teste
        
        // Simula o arquivo de upload usando nosso arquivo de exemplo
        $file = UploadedFile::fake()->createWithContent(
            'cadastral_test.xlsx',
            file_get_contents(base_path('tests/Fixtures/cadastral_test.xlsx'))
        );

        // 2. Executa a Ação
        // Simula uma requisição POST autenticada para a nossa API
        $response = $this->actingAs($user)->postJson('/api/import', [
            'file' => $file,
            'type' => 'cadastral',
            'origin' => 'Teste Automatizado',
        ]);

        // 3. Verifica os Resultados (Asserts)
        $response->assertStatus(202) // Verifica se a resposta foi "Accepted"
                 ->assertJson([
                     'message' => 'Arquivo recebido. A importação foi iniciada e será processada em segundo plano.',
                 ]);

        // Verifica se o ImportJob foi criado corretamente no banco de dados
        $this->assertDatabaseHas('import_jobs', [
            'user_id' => $user->id,
            'type' => 'cadastral',
            'origin' => 'Teste Automatizado',
            'file_name' => 'cadastral_test.xlsx',
            'status' => 'pendente',
        ]);

        // Verifica se o nosso Job de processamento foi enviado para a fila
        Queue::assertPushed(ProcessLeadImportJob::class);
    }

    /**
     * Testa o upload de uma planilha de higienização.
     */
    public function test_it_can_accept_a_higienizacao_import_file(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        
        $file = UploadedFile::fake()->createWithContent(
            'higienizacao_test.xlsx',
            file_get_contents(base_path('tests/Fixtures/higienizacao_test.xlsx'))
        );

        $response = $this->actingAs($user)->postJson('/api/import', [
            'file' => $file,
            'type' => 'higienizacao',
        ]);

        $response->assertStatus(202);
        
        $this->assertDatabaseHas('import_jobs', [
            'type' => 'higienizacao',
            'file_name' => 'higienizacao_test.xlsx',
        ]);

        Queue::assertPushed(ProcessLeadImportJob::class);
    }
}