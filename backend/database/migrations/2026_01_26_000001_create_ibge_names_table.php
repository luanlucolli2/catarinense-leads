<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ibge_names', function (Blueprint $table) {
            $table->id();
            // Index é crucial aqui para a busca ser rápida
            $table->string('name')->unique()->index(); 
            // char(1) é mais leve que string/varchar para 'M' ou 'F'
            $table->char('gender', 1); 
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ibge_names');
    }
};