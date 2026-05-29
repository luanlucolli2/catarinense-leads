<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendeai_webhook_counters', function (Blueprint $table) {
            $table->id();
            $table->string('event', 80)->unique();
            $table->unsignedBigInteger('received_count')->default(0);
            $table->timestamp('last_received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendeai_webhook_counters');
    }
};
