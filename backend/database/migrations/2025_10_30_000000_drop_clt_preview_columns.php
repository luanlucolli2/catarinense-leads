<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            // Remoção segura: só dropa se existir
            $cols = [
                'preview_disk','preview_path','preview_name','preview_updated_at','preview_dirty',
                'preview_status','preview_requested_at','preview_started_at','preview_finished_at',
                'preview_size_bytes','preview_rows','preview_error',
            ];
            foreach ($cols as $c) {
                if (Schema::hasColumn('clt_consult_jobs', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            // Recria com tipos compatíveis (valores padrão razoáveis)
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_disk')) $table->string('preview_disk', 50)->nullable()->after('spool_bytes');
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_path')) $table->string('preview_path', 255)->nullable()->after('preview_disk');
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_name')) $table->string('preview_name', 191)->nullable()->after('preview_path');
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_updated_at')) $table->timestamp('preview_updated_at')->nullable()->after('preview_name');
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_dirty')) $table->boolean('preview_dirty')->default(false)->after('preview_updated_at');
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_status')) $table->string('preview_status', 20)->default('none')->after('preview_dirty');
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_requested_at')) $table->timestamp('preview_requested_at')->nullable()->after('preview_status');
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_started_at')) $table->timestamp('preview_started_at')->nullable()->after('preview_requested_at');
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_finished_at')) $table->timestamp('preview_finished_at')->nullable()->after('preview_started_at');
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_size_bytes')) $table->unsignedBigInteger('preview_size_bytes')->default(0)->after('preview_finished_at');
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_rows')) $table->unsignedBigInteger('preview_rows')->default(0)->after('preview_size_bytes');
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_error')) $table->text('preview_error')->nullable()->after('preview_rows');
        });
    }
};
