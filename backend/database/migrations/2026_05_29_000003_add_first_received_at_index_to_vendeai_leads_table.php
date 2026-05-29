<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendeai_leads', function (Blueprint $table) {
            $table->index('first_received_at', 'idx_vendeai_first_received_at');
        });
    }

    public function down(): void
    {
        Schema::table('vendeai_leads', function (Blueprint $table) {
            $table->dropIndex('idx_vendeai_first_received_at');
        });
    }
};
