<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fgts_off_consult_jobs', function (Blueprint $table) {
            // existirão em bancos já rodando; usar dropIfExists não existe, então checagens via try/catch são delegadas ao SGBD
            foreach ([
                'preview_disk','preview_path','preview_name','preview_updated_at',
                'preview_dirty','preview_status','preview_requested_at','preview_started_at',
                'preview_finished_at','preview_size_bytes','preview_rows','preview_error',
            ] as $col) {
                try { $table->dropColumn($col); } catch (\Throwable $e) { /* noop */ }
            }
        });
    }

    public function down(): void
    {
        Schema::table('fgts_off_consult_jobs', function (Blueprint $table) {
            $table->string('preview_disk')->nullable();
            $table->string('preview_path')->nullable();
            $table->string('preview_name')->nullable();
            $table->timestamp('preview_updated_at')->nullable();
            $table->boolean('preview_dirty')->default(false);
            $table->string('preview_status')->default('none');
            $table->timestamp('preview_requested_at')->nullable();
            $table->timestamp('preview_started_at')->nullable();
            $table->timestamp('preview_finished_at')->nullable();
            $table->unsignedBigInteger('preview_size_bytes')->default(0);
            $table->unsignedInteger('preview_rows')->default(0);
            $table->text('preview_error')->nullable();
        });
    }
};
