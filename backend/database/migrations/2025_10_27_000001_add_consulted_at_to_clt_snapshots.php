<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('clt_snapshots', 'consulted_at')) {
                $table->dateTime('consulted_at')->nullable()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clt_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('clt_snapshots', 'consulted_at')) {
                $table->dropColumn('consulted_at');
            }
        });
    }
};
