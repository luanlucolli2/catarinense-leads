<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendeai_proposal_created_webhooks', function (Blueprint $table) {
            $table->foreignId('vendeai_lead_id')
                ->nullable()
                ->after('id')
                ->constrained('vendeai_leads')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendeai_proposal_created_webhooks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendeai_lead_id');
        });
    }
};
