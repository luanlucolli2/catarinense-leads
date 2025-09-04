<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fgts_off_consult_jobs', function (Blueprint $table) {
            // Spool
            $table->string('spool_path')->nullable()->after('file_name');
            $table->string('spool_cpfs_path')->nullable()->after('spool_path');
            $table->unsignedBigInteger('spool_bytes')->default(0)->after('spool_cpfs_path');

            // Prévia
            $table->boolean('preview_dirty')->default(false)->after('preview_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('fgts_off_consult_jobs', function (Blueprint $table) {
            $table->dropColumn(['spool_path', 'spool_cpfs_path', 'spool_bytes', 'preview_dirty']);
        });
    }
};
