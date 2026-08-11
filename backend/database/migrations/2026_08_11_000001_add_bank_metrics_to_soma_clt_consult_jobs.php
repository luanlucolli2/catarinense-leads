<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('soma_clt_consult_jobs', function (Blueprint $table) {
            $table->json('bank_metrics')->nullable()->after('phase2_errors_count');
        });
    }

    public function down(): void
    {
        Schema::table('soma_clt_consult_jobs', function (Blueprint $table) {
            $table->dropColumn('bank_metrics');
        });
    }
};
