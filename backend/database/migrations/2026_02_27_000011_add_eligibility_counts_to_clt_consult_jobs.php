<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('clt_consult_jobs', 'elegivel_count')) {
                $table->unsignedInteger('elegivel_count')->default(0)->after('total_cpfs');
            }
            if (!Schema::hasColumn('clt_consult_jobs', 'inelegivel_count')) {
                $table->unsignedInteger('inelegivel_count')->default(0)->after('elegivel_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('clt_consult_jobs', 'inelegivel_count')) {
                $table->dropColumn('inelegivel_count');
            }
            if (Schema::hasColumn('clt_consult_jobs', 'elegivel_count')) {
                $table->dropColumn('elegivel_count');
            }
        });
    }
};
