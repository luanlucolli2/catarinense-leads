<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            // Spool
            if (!Schema::hasColumn('clt_consult_jobs', 'spool_path')) {
                $table->string('spool_path', 512)->nullable()->after('file_name');
            }
            if (!Schema::hasColumn('clt_consult_jobs', 'spool_cpfs_path')) {
                $table->string('spool_cpfs_path', 512)->nullable()->after('spool_path');
            }
            if (!Schema::hasColumn('clt_consult_jobs', 'spool_bytes')) {
                $table->unsignedBigInteger('spool_bytes')->default(0)->after('spool_cpfs_path');
            }

            // Prévia: flag de sujeira (regenerar)
            if (!Schema::hasColumn('clt_consult_jobs', 'preview_dirty')) {
                $table->boolean('preview_dirty')->default(false)->after('preview_updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('clt_consult_jobs', 'spool_path')) {
                $table->dropColumn('spool_path');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'spool_cpfs_path')) {
                $table->dropColumn('spool_cpfs_path');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'spool_bytes')) {
                $table->dropColumn('spool_bytes');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'preview_dirty')) {
                $table->dropColumn('preview_dirty');
            }
        });
    }
};
