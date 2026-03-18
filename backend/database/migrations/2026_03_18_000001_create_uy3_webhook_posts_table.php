<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uy3_webhook_posts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->json('payload');
            $table->timestamp('received_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uy3_webhook_posts');
    }
};
