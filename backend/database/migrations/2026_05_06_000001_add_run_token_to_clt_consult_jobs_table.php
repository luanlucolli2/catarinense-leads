<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('clt_consult_jobs', 'run_token')) {
                $table->unsignedBigInteger('run_token')->default(1)->after('phase');
            }
        });

        DB::table('clt_consult_jobs')
            ->whereNull('run_token')
            ->update(['run_token' => 1]);
    }

    public function down(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('clt_consult_jobs', 'run_token')) {
                $table->dropColumn('run_token');
            }
        });
    }
};
