<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Lead;
use App\Models\LeadContract;

class LeadSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Limpa as tabelas na ordem correta
        LeadContract::query()->delete();
        Lead::query()->delete();

        // --- DADOS DE TESTE SIMULANDO DADOS REAIS E NÃO FORMATADOS ---

        // Lead 1 - Elegível, com telefone de 11 dígitos sem formatação
        $lead1 = Lead::create([
            'cpf'                   => '00972876291', // CPF sem formatação
            'nome'                  => 'Maria Elegível Teste',
            'data_nascimento'       => '1992-08-10',
            'fone1'                 => '47991234567', // Telefone com 11 dígitos, sem formatação
            'classe_fone1'          => 'Quente',
            'origem_cadastro'       => 'Sistema Interno',
            'data_importacao_cadastro' => now()->subDays(20),
            'consulta'              => 'Saldo FACTA',
            'data_atualizacao'      => '2025-06-28 03:33:42',
            'saldo'                 => '219.42',
            'libera'                => '103.58',
        ]);

        LeadContract::create(['lead_id' => $lead1->id, 'data_contrato' => '2024-01-10']);

        // Lead 2 - Inelegível, com múltiplos telefones em formatos variados
        Lead::create([
            'cpf'                   => '35684595820',
            'nome'                  => 'João Não Autorizado',
            'data_nascimento'       => '1988-03-25',
            'fone1'                 => '5521988883333', // Com código do país
            'classe_fone1'          => 'Frio',
            'fone2'                 => '(85) 3456-7890',  // Telefone fixo com máscara
            'classe_fone2'          => 'Frio',
            'fone3'                 => '47991234567',     // Telefone repetido para teste
            'classe_fone3'          => 'Quente',
            'fone4'                 => '1123456789',      // Telefone com 10 dígitos
            'classe_fone4'          => 'Frio',
            'origem_cadastro'       => 'Planilha Higienização',
            'data_importacao_cadastro' => now()->subDays(15),
            'consulta'              => 'Instituição Fiduciária não possui autorização do Trabalhador para Operação Fiduciária. (7)',
            'data_atualizacao'      => '2025-06-20 03:39:10',
            'saldo'                 => 'Não Autorizado',
            'libera'                => 'Não Autorizado',
        ]);

        // Lead 3 - Outro caso de não autorizado, sem telefone secundário
        Lead::create([
            'cpf'                   => '19730102759',
            'nome'                  => 'Carla Sem Permissão',
            'data_nascimento'       => '1979-01-01',
            'fone1'                 => '11977774444', // 11 dígitos sem formatação
            'classe_fone1'          => 'Frio',
            'origem_cadastro'       => 'Planilha Higienização',
            'data_importacao_cadastro' => now()->subDays(12),
            'consulta'              => 'Instituição Fiduciária não possui autorização do Trabalhador para Operação Fiduciária. (7)',
            'data_atualizacao'      => '2025-06-20 01:06:28',
            'saldo'                 => 'Não Autorizado',
            'libera'                => 'Não Autorizado',
        ]);
    }
}