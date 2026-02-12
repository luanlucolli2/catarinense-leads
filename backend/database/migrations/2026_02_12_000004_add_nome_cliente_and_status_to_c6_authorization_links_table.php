<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('c6_authorization_links', function (Blueprint $table) {
            if (! Schema::hasColumn('c6_authorization_links', 'nome_cliente')) {
                $table->string('nome_cliente', 255)->nullable()->after('cpf');
            }

            if (! Schema::hasColumn('c6_authorization_links', 'status')) {
                $table->string('status', 20)->default('ativo')->after('expires_at');
            }
        });

        DB::table('c6_authorization_links')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expirado',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('c6_authorization_links', function (Blueprint $table) {
            if (Schema::hasColumn('c6_authorization_links', 'status')) {
                $table->dropColumn('status');
            }

            if (Schema::hasColumn('c6_authorization_links', 'nome_cliente')) {
                $table->dropColumn('nome_cliente');
            }
        });
    }
};
