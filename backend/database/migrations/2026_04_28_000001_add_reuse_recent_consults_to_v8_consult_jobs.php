<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v8_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('v8_consult_jobs', 'reuse_recent_consults')) {
                $table->boolean('reuse_recent_consults')->default(false)->after('phase');
            }

            if (!Schema::hasColumn('v8_consult_jobs', 'reuse_recent_consults_days')) {
                $table->unsignedSmallInteger('reuse_recent_consults_days')->nullable()->default(30)->after('reuse_recent_consults');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v8_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('v8_consult_jobs', 'reuse_recent_consults_days')) {
                $table->dropColumn('reuse_recent_consults_days');
            }

            if (Schema::hasColumn('v8_consult_jobs', 'reuse_recent_consults')) {
                $table->dropColumn('reuse_recent_consults');
            }
        });
    }
};
