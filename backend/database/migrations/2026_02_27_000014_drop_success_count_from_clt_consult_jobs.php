<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('clt_consult_jobs', 'success_count')) {
                $table->dropColumn('success_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('clt_consult_jobs', 'success_count')) {
                $table->unsignedInteger('success_count')->default(0)->after('inelegivel_count');
            }
        });
    }
};
