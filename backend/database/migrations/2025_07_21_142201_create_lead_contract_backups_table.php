<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_contract_backups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('import_job_id')
                  ->constrained('import_jobs')
                  ->onDelete('cascade');

            $table->foreignId('lead_id')
                  ->constrained('leads')
                  ->onDelete('cascade');

            // contrato original (pode ser null quando action = insert)
            $table->unsignedBigInteger('lead_contract_id')->nullable();
            $table->foreign('lead_contract_id')
                  ->references('id')->on('lead_contracts')
                  ->nullOnDelete();

            $table->foreignId('vendor_id')
                  ->nullable()
                  ->constrained('vendors')
                  ->nullOnDelete();

            $table->date('data_contrato');

            // por enquanto só precisamos distinguir inserções
            $table->enum('action', ['insert'])->default('insert');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_contract_backups');
    }
};
