<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendeai_newcorban_proposal_attempts', function (Blueprint $table) {
            $table->json('newcorban_request_payload')->nullable();
            $table->unsignedSmallInteger('newcorban_response_status')->nullable();
            $table->json('newcorban_response_body')->nullable();
            $table->string('newcorban_proposta_id', 80)->nullable();
            $table->string('newcorban_cliente_id', 80)->nullable();
            $table->timestamp('newcorban_sent_at')->nullable();
            $table->text('newcorban_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vendeai_newcorban_proposal_attempts', function (Blueprint $table) {
            $table->dropColumn([
                'newcorban_request_payload',
                'newcorban_response_status',
                'newcorban_response_body',
                'newcorban_proposta_id',
                'newcorban_cliente_id',
                'newcorban_sent_at',
                'newcorban_error',
            ]);
        });
    }
};
