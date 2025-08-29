<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            $table->unsignedInteger('not_found_count')
                ->default(0)
                ->after('fail_count');
        });
    }

    public function down(): void
    {
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            $table->dropColumn('not_found_count');
        });
    }
};
