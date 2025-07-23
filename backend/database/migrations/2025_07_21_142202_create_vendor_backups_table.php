<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_backups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('import_job_id')
                  ->constrained('import_jobs')
                  ->onDelete('cascade');

            // vendor criado durante o job
            $table->foreignId('vendor_id')
                  ->constrained('vendors')
                  ->onDelete('cascade');

            $table->string('name');
            $table->string('name_clean');
            $table->timestamp('original_created_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_backups');
    }
};
