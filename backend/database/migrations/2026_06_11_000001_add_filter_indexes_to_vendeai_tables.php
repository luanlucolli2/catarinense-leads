<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendeai_leads', function (Blueprint $table) {
            $table->index('last_received_at', 'idx_vendeai_last_received_at');
        });

        Schema::table('vendeai_newcorban_proposal_attempts', function (Blueprint $table) {
            $table->index('newcorban_sent_at', 'idx_vendeai_attempts_newcorban_sent_at');
            $table->index('newcorban_proposta_id', 'idx_vendeai_attempts_newcorban_proposta_id');
        });
    }

    public function down(): void
    {
        Schema::table('vendeai_leads', function (Blueprint $table) {
            $table->dropIndex('idx_vendeai_last_received_at');
        });

        Schema::table('vendeai_newcorban_proposal_attempts', function (Blueprint $table) {
            $table->dropIndex('idx_vendeai_attempts_newcorban_sent_at');
            $table->dropIndex('idx_vendeai_attempts_newcorban_proposta_id');
        });
    }
};
