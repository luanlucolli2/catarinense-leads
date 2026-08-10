<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('soma_clt_consult_jobs', function (Blueprint $table) {
            $table->unsignedInteger('phase1_pending_count')->default(0)->after('fail_count');
            $table->unsignedInteger('phase1_success_count')->default(0)->after('phase1_pending_count');
            $table->unsignedInteger('phase1_declined_count')->default(0)->after('phase1_success_count');
            $table->unsignedInteger('phase1_errors_count')->default(0)->after('phase1_declined_count');
            $table->unsignedInteger('phase2_success_count')->default(0)->after('phase1_errors_count');
            $table->unsignedInteger('phase2_declined_count')->default(0)->after('phase2_success_count');
            $table->unsignedInteger('phase2_errors_count')->default(0)->after('phase2_declined_count');
        });
    }

    public function down(): void
    {
        Schema::table('soma_clt_consult_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'phase1_pending_count', 'phase1_success_count', 'phase1_declined_count', 'phase1_errors_count',
                'phase2_success_count', 'phase2_declined_count', 'phase2_errors_count',
            ]);
        });
    }
};
