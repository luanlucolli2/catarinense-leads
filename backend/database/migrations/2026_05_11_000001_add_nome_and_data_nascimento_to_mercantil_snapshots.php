<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('mercantil_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('mercantil_snapshots', 'nome')) {
                $table->string('nome', 150)->nullable()->after('cpf');
            }

            if (!Schema::hasColumn('mercantil_snapshots', 'data_nascimento')) {
                $table->date('data_nascimento')->nullable()->after('nome');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mercantil_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('mercantil_snapshots', 'data_nascimento')) {
                $table->dropColumn('data_nascimento');
            }

            if (Schema::hasColumn('mercantil_snapshots', 'nome')) {
                $table->dropColumn('nome');
            }
        });
    }
};
