<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'teste@catarinense.com'],
            [
                'name' => 'Usuário de Teste',
                'password' => 'password',
            ]
        );

        // Conta compartilhada para operação do módulo de links C6.
        User::updateOrCreate(
            ['email' => 'c6.links@catarinense.com'],
            [
                'name' => 'Operador Links C6',
                'password' => 'gratiluz123',
            ]
        );

        User::updateOrCreate(
            ['email' => 'alessandra@catarinensecredito.com.br'],
            [
                'name' => 'Alessandra Vitancourt',
                'password' => 'catarinense123',
            ]
        );
    }
}
