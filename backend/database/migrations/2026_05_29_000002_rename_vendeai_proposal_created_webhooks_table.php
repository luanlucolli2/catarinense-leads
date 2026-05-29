<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('vendeai_proposal_created_webhooks') &&
            ! Schema::hasTable('vendeai_newcorban_proposal_attempts')
        ) {
            Schema::rename('vendeai_proposal_created_webhooks', 'vendeai_newcorban_proposal_attempts');
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('vendeai_newcorban_proposal_attempts') &&
            ! Schema::hasTable('vendeai_proposal_created_webhooks')
        ) {
            Schema::rename('vendeai_newcorban_proposal_attempts', 'vendeai_proposal_created_webhooks');
        }
    }
};
