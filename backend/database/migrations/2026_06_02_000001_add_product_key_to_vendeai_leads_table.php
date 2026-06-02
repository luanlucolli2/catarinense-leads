<?php

use App\Modules\Vendeai\Services\BackfillVendeaiLeadProductKeysService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendeai_leads', function (Blueprint $table) {
            $table->string('product_key', 30)->nullable()->after('chat_id');
            $table->dropUnique('uniq_vendeai_account_chat');
            $table->unique(['account_id', 'chat_id', 'product_key'], 'uniq_vendeai_account_chat_product');
            $table->index('product_key', 'idx_vendeai_product_key');
        });

        app(BackfillVendeaiLeadProductKeysService::class)->handle();
    }

    public function down(): void
    {
        Schema::table('vendeai_leads', function (Blueprint $table) {
            $table->dropIndex('idx_vendeai_product_key');
            $table->dropUnique('uniq_vendeai_account_chat_product');
            $table->dropColumn('product_key');
            $table->unique(['account_id', 'chat_id'], 'uniq_vendeai_account_chat');
        });
    }
};
