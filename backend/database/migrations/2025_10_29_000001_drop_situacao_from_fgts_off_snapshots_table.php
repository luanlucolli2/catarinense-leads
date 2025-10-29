<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fgts_off_snapshots', 'situacao')) {
            Schema::table('fgts_off_snapshots', function (Blueprint $table) {
                $table->dropColumn('situacao');
            });
        }
    }

    public function down(): void
    {
        // Recria a coluna caso precise fazer rollback.
        Schema::table('fgts_off_snapshots', function (Blueprint $table) {
            $table->string('situacao', 191)->nullable()->after('lead_id');
        });
    }
};
