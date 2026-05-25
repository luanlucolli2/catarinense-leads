<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendeai_proposal_created_webhooks', function (Blueprint $table) {
            $table->id();
            $table->timestamp('received_at')->index();

            $table->string('account_id', 80)->nullable();
            $table->string('chat_id', 80)->nullable()->index();
            $table->string('chat_product', 40)->nullable();
            $table->string('chat_stage', 80)->nullable();

            $table->string('contact_name', 180)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email', 180)->nullable();
            $table->string('contact_cpf', 20)->nullable();
            $table->date('contact_birth_date')->nullable();
            $table->string('contact_mother_name', 180)->nullable();

            $table->string('session_campaign', 180)->nullable();
            $table->string('session_inbox_phone_number', 40)->nullable();
            $table->string('session_product_being_processed', 40)->nullable();
            $table->json('tags')->nullable();

            $table->string('proposal_id', 120)->nullable()->index();
            $table->string('proposal_number', 80)->nullable();
            $table->string('proposal_status', 80)->nullable();
            $table->string('bank', 40)->nullable();
            $table->string('proposal_product', 40)->nullable();
            $table->decimal('liquid_value', 12, 2)->nullable();
            $table->decimal('gross_value', 12, 2)->nullable();
            $table->unsignedInteger('number_of_payments')->nullable();
            $table->decimal('installment_value', 12, 2)->nullable();
            $table->string('table_name', 180)->nullable();
            $table->string('table_id', 120)->nullable();
            $table->text('formalization_link')->nullable();

            $table->json('raw_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendeai_proposal_created_webhooks');
    }
};
