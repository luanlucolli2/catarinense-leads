<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendeai_leads', function (Blueprint $table) {
            $table->id();
            $table->string('account_id', 50);
            $table->string('chat_id', 100);
            $table->string('last_event', 50)->nullable();

            $table->string('chat_product', 30)->nullable();
            $table->string('stage', 100)->nullable();
            $table->json('tags')->nullable();
            $table->string('campaign', 150)->nullable();
            $table->string('inbox_phone_number', 30)->nullable();
            $table->string('product_being_processed', 30)->nullable();

            $table->string('customer_name', 255)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_email', 255)->nullable();
            $table->string('customer_cpf', 20)->nullable();
            $table->date('customer_birth_date')->nullable();
            $table->string('customer_mother_name', 255)->nullable();

            $table->string('simulation_product', 30)->nullable();
            $table->string('simulation_bank', 50)->nullable();
            $table->decimal('simulation_liquid_value', 12, 2)->nullable();
            $table->unsignedInteger('simulation_number_of_payments')->nullable();
            $table->decimal('simulation_installment_value', 12, 2)->nullable();
            $table->decimal('simulation_monthly_fee', 8, 4)->nullable();
            $table->string('simulation_table_name', 255)->nullable();
            $table->string('simulation_table_id', 100)->nullable();
            $table->decimal('simulation_best_liquid_value', 12, 2)->nullable();
            $table->string('simulation_best_table_id', 100)->nullable();
            $table->json('simulation_table_details')->nullable();
            $table->timestamp('simulation_received_at')->nullable();

            $table->string('proposal_id', 100)->nullable();
            $table->string('proposal_number', 100)->nullable();
            $table->string('proposal_status', 100)->nullable();
            $table->string('previous_proposal_status', 100)->nullable();
            $table->string('proposal_bank', 50)->nullable();
            $table->string('proposal_product', 30)->nullable();
            $table->decimal('proposal_liquid_value', 12, 2)->nullable();
            $table->decimal('proposal_gross_value', 12, 2)->nullable();
            $table->unsignedInteger('proposal_number_of_payments')->nullable();
            $table->decimal('proposal_installment_value', 12, 2)->nullable();
            $table->string('proposal_table_name', 255)->nullable();
            $table->string('proposal_table_id', 100)->nullable();
            $table->text('proposal_formalization_link')->nullable();
            $table->timestamp('proposal_created_at')->nullable();
            $table->timestamp('proposal_status_updated_at')->nullable();

            $table->timestamp('first_received_at')->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->json('last_payload')->nullable();

            $table->timestamps();

            $table->unique(['account_id', 'chat_id'], 'uniq_vendeai_account_chat');
            $table->index('customer_cpf', 'idx_vendeai_cpf');
            $table->index('customer_phone', 'idx_vendeai_phone');
            $table->index('proposal_id', 'idx_vendeai_proposal_id');
            $table->index('stage', 'idx_vendeai_stage');
            $table->index('proposal_status', 'idx_vendeai_proposal_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendeai_leads');
    }
};
