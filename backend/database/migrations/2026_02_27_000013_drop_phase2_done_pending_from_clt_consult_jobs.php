<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('clt_consult_jobs', 'phase2_pending')) {
                $table->dropColumn('phase2_pending');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'phase2_done')) {
                $table->dropColumn('phase2_done');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('clt_consult_jobs', 'phase2_done')) {
                $table->unsignedInteger('phase2_done')->default(0)->after('phase2_total');
            }
            if (!Schema::hasColumn('clt_consult_jobs', 'phase2_pending')) {
                $table->unsignedInteger('phase2_pending')->default(0)->after('phase2_done');
            }
        });
    }
};
