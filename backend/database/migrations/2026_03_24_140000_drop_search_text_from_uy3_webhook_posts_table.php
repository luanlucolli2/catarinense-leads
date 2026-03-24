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

        if ($this->indexExists('uy3_webhook_posts', 'uy3_webhook_posts_search_text_fulltext')) {
            DB::statement('ALTER TABLE uy3_webhook_posts DROP INDEX uy3_webhook_posts_search_text_fulltext');
        }

        if (Schema::hasColumn('uy3_webhook_posts', 'search_text')) {
            Schema::table('uy3_webhook_posts', function (Blueprint $table) {
                $table->dropColumn('search_text');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('uy3_webhook_posts')) {
            return;
        }

        if (! Schema::hasColumn('uy3_webhook_posts', 'search_text')) {
            Schema::table('uy3_webhook_posts', function (Blueprint $table) {
                $table->longText('search_text')->nullable()->after('payload');
            });
        }

        DB::statement(
            "UPDATE uy3_webhook_posts
             SET search_text = LEFT(LOWER(CAST(payload AS CHAR)), 12000)
             WHERE search_text IS NULL OR search_text = ''"
        );

        if (! $this->indexExists('uy3_webhook_posts', 'uy3_webhook_posts_search_text_fulltext')) {
            DB::statement(
                'ALTER TABLE uy3_webhook_posts
                 ADD FULLTEXT INDEX uy3_webhook_posts_search_text_fulltext (search_text)'
            );
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

