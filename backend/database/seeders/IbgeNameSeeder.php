<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class IbgeNameSeeder extends Seeder
{
    public function run(): void
    {
        // Limpa a tabela antes de começar
        DB::table('ibge_names')->truncate();

        // Garante que o arquivo esteja em backend/storage/app/nomesibge.csv
        $path = storage_path('app/nomesibge.csv');

        LazyCollection::make(function () use ($path) {
            $handle = fopen($path, 'r');
            
            // --- CORREÇÃO 1: Pular o cabeçalho ---
            // Lê a primeira linha e descarta, para não tentar inserir "first_name" no banco
            fgetcsv($handle, 0, ';');
            
            // --- CORREÇÃO 2: Definir o separador como ';' ---
            // O segundo parametro (0) é o tamanho do buffer (0 = ilimitado)
            // O terceiro (';') é o separador visto no seu head
            while (($line = fgetcsv($handle, 0, ';')) !== false) {
                
                // Validação básica para evitar linhas em branco no final do arquivo
                if (!isset($line[0]) || !isset($line[1])) {
                    continue;
                }

                yield [
                    'name'   => strtoupper(trim($line[0])), 
                    'gender' => strtoupper(trim($line[1])), 
                ];
            }
            
            fclose($handle);
        })
        ->chunk(1000) 
        ->each(function ($chunk) {
            DB::table('ibge_names')->insert($chunk->toArray());
        });
    }
}