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

        DB::statement(<<<'SQL'
            UPDATE uy3_webhook_posts
            SET payload = CASE
                WHEN JSON_EXTRACT(payload, '$.typeWebhook') IS NULL
                    THEN JSON_REMOVE(
                        JSON_SET(payload, '$.typeWebhook', JSON_EXTRACT(payload, '$.typeWebook')),
                        '$.typeWebook'
                    )
                ELSE JSON_REMOVE(payload, '$.typeWebook')
            END
            WHERE JSON_EXTRACT(payload, '$.typeWebook') IS NOT NULL
        SQL);

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

        Schema::table('uy3_webhook_posts', function (Blueprint $table) {
            $table->string('type_webhook', 40)
                ->storedAs("JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.typeWebhook'))")
                ->nullable()
                ->after('payload');
        });

        Schema::table('uy3_webhook_posts', function (Blueprint $table) {
            $table->index(['type_webhook', 'received_at', 'id'], 'uy3_webhook_posts_type_received_id_index');
        });
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

        Schema::table('uy3_webhook_posts', function (Blueprint $table) {
            $table->string('type_webhook', 40)
                ->storedAs("JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.typeWebook'))")
                ->nullable()
                ->after('payload');
        });

        Schema::table('uy3_webhook_posts', function (Blueprint $table) {
            $table->index(['type_webhook', 'received_at', 'id'], 'uy3_webhook_posts_type_received_id_index');
        });
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
