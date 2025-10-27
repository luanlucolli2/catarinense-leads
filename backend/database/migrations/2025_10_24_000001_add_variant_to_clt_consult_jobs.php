<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('clt_consult_jobs', 'variant')) {
                $table->string('variant', 16)->default('online')->after('title');
                $table->index('variant', 'idx_clt_jobs_variant');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('clt_consult_jobs', 'variant')) {
                $table->dropIndex('idx_clt_jobs_variant');
                $table->dropColumn('variant');
            }
        });
    }
};
