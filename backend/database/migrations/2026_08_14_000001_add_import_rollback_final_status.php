<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->string('rollback_final_status', 20)->nullable()->after('rolled_back_at');
        });
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropColumn('rollback_final_status');
        });
    }
};
