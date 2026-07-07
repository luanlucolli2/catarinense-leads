<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendeai_leads', function (Blueprint $table) {
            $table->index('inbox_phone_number', 'idx_vendeai_inbox_phone_number');
        });
    }

    public function down(): void
    {
        Schema::table('vendeai_leads', function (Blueprint $table) {
            $table->dropIndex('idx_vendeai_inbox_phone_number');
        });
    }
};
