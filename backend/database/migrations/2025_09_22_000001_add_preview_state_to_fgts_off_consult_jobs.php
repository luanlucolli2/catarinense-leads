<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fgts_off_consult_jobs', function (Blueprint $table) {
            // Estado/telemetria da PRÉVIA
            $table->string('preview_status', 20)->default('none')->after('preview_dirty'); // none|queued|running|ready|error
            $table->timestamp('preview_requested_at')->nullable()->after('preview_status');
            $table->timestamp('preview_started_at')->nullable()->after('preview_requested_at');
            $table->timestamp('preview_finished_at')->nullable()->after('preview_started_at');
            $table->unsignedBigInteger('preview_size_bytes')->default(0)->after('preview_finished_at');
            $table->unsignedInteger('preview_rows')->default(0)->after('preview_size_bytes');
            $table->text('preview_error')->nullable()->after('preview_rows');

            // índices úteis
            $table->index(['preview_status']);
            $table->index(['user_id', 'preview_status']);
        });
    }

    public function down(): void
    {
        Schema::table('fgts_off_consult_jobs', function (Blueprint $table) {
            $table->dropIndex(['preview_status']);
            $table->dropIndex(['user_id', 'preview_status']);
            $table->dropColumn([
                'preview_status',
                'preview_requested_at',
                'preview_started_at',
                'preview_finished_at',
                'preview_size_bytes',
                'preview_rows',
                'preview_error',
            ]);
        });
    }
};
