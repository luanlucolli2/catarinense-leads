// Em database/migrations/..._create_leads_table.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('cpf', 11)->unique();
            $table->string('nome')->nullable();
            $table->date('data_nascimento')->nullable();
            $table->string('fone1')->nullable();
            $table->string('classe_fone1')->nullable();
            $table->string('fone2')->nullable();
            $table->string('classe_fone2')->nullable();
            $table->string('fone3')->nullable();
            $table->string('classe_fone3')->nullable();
            $table->string('fone4')->nullable();
            $table->string('classe_fone4')->nullable();
            
            // REMOVEMOS 'origem_cadastro' e 'data_importacao_cadastro' daqui
            
            $table->string('consulta')->nullable();
            $table->timestamp('data_atualizacao')->nullable();
            $table->string('saldo')->nullable();
            $table->string('libera')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};