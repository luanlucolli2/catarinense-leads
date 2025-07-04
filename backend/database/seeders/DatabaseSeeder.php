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
        User::create([
             'name' => 'Usuário de Teste',
             'email' => 'teste@catarinense.com',
             'password' => 'password'
        ]);
        
        // Adicione esta linha:
        $this->call(LeadSeeder::class);
    }
}