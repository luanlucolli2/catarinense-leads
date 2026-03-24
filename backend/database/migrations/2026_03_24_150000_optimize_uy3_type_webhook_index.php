<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('uy3_webhook_posts')) {
            return;
        }

        if (! Schema::hasColumn('uy3_webhook_posts', 'type_webhook')) {
            Schema::table('uy3_webhook_posts', function (Blueprint $table) {
                $table->string('type_webhook', 40)
                    ->storedAs("JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.typeWebook'))")
                    ->nullable()
                    ->after('payload');
            });
        }

        if (! $this->indexExists('uy3_webhook_posts', 'uy3_webhook_posts_type_received_id_index')) {
            Schema::table('uy3_webhook_posts', function (Blueprint $table) {
                $table->index(['type_webhook', 'received_at', 'id'], 'uy3_webhook_posts_type_received_id_index');
            });
        }

        if (
            $this->indexExists('uy3_webhook_posts', 'uy3_webhook_posts_received_at_index') &&
            $this->indexExists('uy3_webhook_posts', 'uy3_webhook_posts_received_at_id_index')
        ) {
            Schema::table('uy3_webhook_posts', function (Blueprint $table) {
                $table->dropIndex('uy3_webhook_posts_received_at_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('uy3_webhook_posts')) {
            return;
        }

        if ($this->indexExists('uy3_webhook_posts', 'uy3_webhook_posts_type_received_id_index')) {
            Schema::table('uy3_webhook_posts', function (Blueprint $table) {
                $table->dropIndex('uy3_webhook_posts_type_received_id_index');
            });
        }

        if (Schema::hasColumn('uy3_webhook_posts', 'type_webhook')) {
            Schema::table('uy3_webhook_posts', function (Blueprint $table) {
                $table->dropColumn('type_webhook');
            });
        }

        if (! $this->indexExists('uy3_webhook_posts', 'uy3_webhook_posts_received_at_index')) {
            Schema::table('uy3_webhook_posts', function (Blueprint $table) {
                $table->index('received_at', 'uy3_webhook_posts_received_at_index');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};

