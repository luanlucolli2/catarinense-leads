<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v8_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('v8_consult_jobs', 'nao_elegivel_count')) {
                $table->unsignedInteger('nao_elegivel_count')->default(0)->after('success_count');
            }
            if (!Schema::hasColumn('v8_consult_jobs', 'erro_count')) {
                $table->unsignedInteger('erro_count')->default(0)->after('nao_elegivel_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v8_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('v8_consult_jobs', 'erro_count')) {
                $table->dropColumn('erro_count');
            }
            if (Schema::hasColumn('v8_consult_jobs', 'nao_elegivel_count')) {
                $table->dropColumn('nao_elegivel_count');
            }
        });
    }
};
