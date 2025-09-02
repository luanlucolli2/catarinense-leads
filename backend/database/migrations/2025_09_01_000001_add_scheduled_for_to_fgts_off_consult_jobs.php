<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fgts_off_consult_jobs', function (Blueprint $table) {
            $table->dateTime('scheduled_for')->nullable()->after('finished_at');
            // Opcional: índice para consultas futuras (não estritamente necessário aqui)
            // $table->index('scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::table('fgts_off_consult_jobs', function (Blueprint $table) {
            $table->dropColumn('scheduled_for');
        });
    }
};
