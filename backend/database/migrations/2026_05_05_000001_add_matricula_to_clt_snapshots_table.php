<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('clt_snapshots', 'matricula')) {
                $table->string('matricula', 100)->nullable()->after('meses_admissao');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clt_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('clt_snapshots', 'matricula')) {
                $table->dropColumn('matricula');
            }
        });
    }
};
