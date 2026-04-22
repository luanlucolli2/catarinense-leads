<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            $table->string('preview_disk')->nullable()->after('file_name');
            $table->string('preview_path')->nullable()->after('preview_disk');
            $table->string('preview_name')->nullable()->after('preview_path');
            $table->timestamp('preview_updated_at')->nullable()->after('preview_name');

            // opcional: índice para consultas eventuais
            $table->index(['status', 'preview_updated_at']);
        });
    }

    public function down(): void {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            $table->dropIndex(['status_preview_updated_at_index']);
            $table->dropColumn(['preview_disk','preview_path','preview_name','preview_updated_at']);
        });
    }
};
