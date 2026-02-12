<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('c6_authorization_links', 'nome_cliente')) {
            Schema::table('c6_authorization_links', function (Blueprint $table) {
                $table->dropColumn('nome_cliente');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('c6_authorization_links', 'nome_cliente')) {
            Schema::table('c6_authorization_links', function (Blueprint $table) {
                $table->string('nome_cliente', 255)->nullable()->after('cpf');
            });
        }
    }
};
