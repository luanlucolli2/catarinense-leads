<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('clt_consult_jobs', 'phase2_aprovado_count')) {
                $table->unsignedInteger('phase2_aprovado_count')->default(0)->after('phase2_attempt');
            }
            if (!Schema::hasColumn('clt_consult_jobs', 'phase2_nao_aprovado_count')) {
                $table->unsignedInteger('phase2_nao_aprovado_count')->default(0)->after('phase2_aprovado_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('clt_consult_jobs', 'phase2_nao_aprovado_count')) {
                $table->dropColumn('phase2_nao_aprovado_count');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'phase2_aprovado_count')) {
                $table->dropColumn('phase2_aprovado_count');
            }
        });
    }
};
