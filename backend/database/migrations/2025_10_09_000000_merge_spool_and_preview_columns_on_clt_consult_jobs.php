<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            // SPOOL
            if (!Schema::hasColumn('clt_consult_jobs', 'spool_path')) {
                $table->string('spool_path')->nullable()->after('file_name');
            }
            if (!Schema::hasColumn('clt_consult_jobs', 'spool_cpfs_path')) {
                $table->string('spool_cpfs_path')->nullable()->after('spool_path');
            }
            if (!Schema::hasColumn('clt_consult_jobs', 'spool_bytes')) {
                $table->unsignedBigInteger('spool_bytes')->default(0)->after('spool_cpfs_path');
            }

            // Prévia (flag suja/limpa)
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_dirty')) {
                $table->boolean('preview_dirty')->default(false)->after('preview_updated_at');
            }

            // Prévia (estado e telemetria)
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_status')) {
                $table->string('preview_status', 20)->default('none')->after('preview_dirty');
                $table->timestamp('preview_requested_at')->nullable()->after('preview_status');
                $table->timestamp('preview_started_at')->nullable()->after('preview_requested_at');
                $table->timestamp('preview_finished_at')->nullable()->after('preview_started_at');
                $table->unsignedBigInteger('preview_size_bytes')->default(0)->after('preview_finished_at');
                $table->unsignedInteger('preview_rows')->default(0)->after('preview_size_bytes');
                $table->text('preview_error')->nullable()->after('preview_rows');

                // Índices
                $table->index(['preview_status']);
                $table->index(['user_id', 'preview_status']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            // Remover índices (se a coluna existir, o índice existe)
            if (Schema::hasColumn('clt_consult_jobs', 'preview_status')) {
                $table->dropIndex(['preview_status']);
                $table->dropIndex(['user_id', 'preview_status']);
            }

            // Remover colunas de prévia
            if (Schema::hasColumn('clt_consult_jobs', 'preview_error')) {
                $table->dropColumn('preview_error');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'preview_rows')) {
                $table->dropColumn('preview_rows');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'preview_size_bytes')) {
                $table->dropColumn('preview_size_bytes');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'preview_finished_at')) {
                $table->dropColumn('preview_finished_at');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'preview_started_at')) {
                $table->dropColumn('preview_started_at');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'preview_requested_at')) {
                $table->dropColumn('preview_requested_at');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'preview_status')) {
                $table->dropColumn('preview_status');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'preview_dirty')) {
                $table->dropColumn('preview_dirty');
            }

            // Remover colunas de spool
            if (Schema::hasColumn('clt_consult_jobs', 'spool_bytes')) {
                $table->dropColumn('spool_bytes');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'spool_cpfs_path')) {
                $table->dropColumn('spool_cpfs_path');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'spool_path')) {
                $table->dropColumn('spool_path');
            }
        });
    }
};
