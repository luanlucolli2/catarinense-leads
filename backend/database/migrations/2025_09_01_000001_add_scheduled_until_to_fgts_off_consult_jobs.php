<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fgts_off_consult_jobs', function (Blueprint $table) {
            // armazenamos em UTC (mesmo tipo de scheduled_for)
            $table->dateTime('scheduled_until')->nullable()->after('scheduled_for');
            // opcionalmente:
            $table->index('scheduled_until');
        });
    }

    public function down(): void
    {
        Schema::table('fgts_off_consult_jobs', function (Blueprint $table) {
            $table->dropIndex(['scheduled_until']);
            $table->dropColumn('scheduled_until');
        });
    }
};
