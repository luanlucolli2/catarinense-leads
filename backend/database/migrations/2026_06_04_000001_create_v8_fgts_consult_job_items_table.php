<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v8_fgts_consult_job_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('v8_fgts_consult_jobs')->cascadeOnDelete();
            $table->string('cpf', 11);
            $table->string('state', 30)->default('queued_start');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('first_poll_at')->nullable();
            $table->unsignedSmallInteger('start_attempts')->default(0);
            $table->unsignedSmallInteger('poll_attempts')->default(0);
            $table->text('last_message')->nullable();
            $table->longText('api_error_context')->nullable();
            $table->json('last_phase2_snapshot')->nullable();
            $table->json('result_row')->nullable();
            $table->timestamp('spool_written_at')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'cpf']);
            $table->index(['job_id', 'state', 'next_run_at'], 'v8_fgts_job_items_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v8_fgts_consult_job_items');
    }
};
