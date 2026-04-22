<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v8_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('v8_consult_jobs', 'phase')) {
                $table->string('phase', 20)->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v8_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('v8_consult_jobs', 'phase')) {
                $table->dropColumn('phase');
            }
        });
    }
};
