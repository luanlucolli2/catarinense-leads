<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fgts_off_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('fgts_off_consult_jobs', 'not_authorized_count')) {
                $table->unsignedInteger('not_authorized_count')->default(0)->after('success_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fgts_off_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('fgts_off_consult_jobs', 'not_authorized_count')) {
                $table->dropColumn('not_authorized_count');
            }
        });
    }
};
