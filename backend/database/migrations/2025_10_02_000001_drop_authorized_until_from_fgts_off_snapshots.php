<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('fgts_off_snapshots', 'authorized_until')) {
            Schema::table('fgts_off_snapshots', function (Blueprint $table) {
                $table->dropColumn('authorized_until');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('fgts_off_snapshots', 'authorized_until')) {
            Schema::table('fgts_off_snapshots', function (Blueprint $table) {
                $table->timestamp('authorized_until')->nullable();
            });
        }
    }
};
