<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendeai_newcorban_proposal_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendeai_lead_id')->nullable()->index();
            $table->timestamp('received_at')->index();

            $table->json('raw_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendeai_newcorban_proposal_attempts');
    }
};
