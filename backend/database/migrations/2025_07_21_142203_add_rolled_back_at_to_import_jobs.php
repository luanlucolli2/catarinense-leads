<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->timestamp('rolled_back_at')
                ->nullable()
                ->after('finished_at');
            $table->index('rolled_back_at');      // 👈 índice adicionado

        });
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropColumn('rolled_back_at');
        });
    }
};
