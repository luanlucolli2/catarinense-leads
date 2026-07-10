<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubcredito_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('hubcredito_consult_jobs', 'pendencia_count')) {
                $table->dropColumn('pendencia_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hubcredito_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('hubcredito_consult_jobs', 'pendencia_count')) {
                $table->unsignedInteger('pendencia_count')->default(0)->after('nao_aprovado_count');
            }
        });
    }
};
