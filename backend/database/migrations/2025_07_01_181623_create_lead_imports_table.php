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
        Schema::create('lead_imports', function (Blueprint $table) {
            $table->primary(['lead_id', 'import_job_id']); // Chave primária composta
            $table->foreignId('lead_id')->constrained('leads')->onDelete('cascade');
            $table->foreignId('import_job_id')->constrained('import_jobs')->onDelete('cascade');
            $table->string('action'); // 'insert' ou 'update'
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_imports');
    }
};