<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasIndex('c6_authorization_links', 'c6_links_user_cpf_generated_id_idx')) {
            Schema::table('c6_authorization_links', function (Blueprint $table) {
                $table->index(
                    ['user_id', 'cpf', 'generated_at', 'id'],
                    'c6_links_user_cpf_generated_id_idx'
                );
            });
        }

        if (! $this->hasIndex('c6_authorization_links', 'c6_links_user_expires_generated_id_idx')) {
            Schema::table('c6_authorization_links', function (Blueprint $table) {
                $table->index(
                    ['user_id', 'expires_at', 'generated_at', 'id'],
                    'c6_links_user_expires_generated_id_idx'
                );
            });
        }

        // Índice redundante (user_id apenas). Já coberto pelos índices compostos.
        if ($this->hasIndex('c6_authorization_links', 'c6_authorization_links_user_id_nome_cliente_index')) {
            Schema::table('c6_authorization_links', function (Blueprint $table) {
                $table->dropIndex('c6_authorization_links_user_id_nome_cliente_index');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('c6_authorization_links', 'c6_links_user_cpf_generated_id_idx')) {
            Schema::table('c6_authorization_links', function (Blueprint $table) {
                $table->dropIndex('c6_links_user_cpf_generated_id_idx');
            });
        }

        if ($this->hasIndex('c6_authorization_links', 'c6_links_user_expires_generated_id_idx')) {
            Schema::table('c6_authorization_links', function (Blueprint $table) {
                $table->dropIndex('c6_links_user_expires_generated_id_idx');
            });
        }

        if (! $this->hasIndex('c6_authorization_links', 'c6_authorization_links_user_id_nome_cliente_index')) {
            Schema::table('c6_authorization_links', function (Blueprint $table) {
                $table->index('user_id', 'c6_authorization_links_user_id_nome_cliente_index');
            });
        }
    }

    private function hasIndex(string $tableName, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $tableName)
            ->where('index_name', $indexName)
            ->exists();
    }
};
