<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendeai_newcorban_proposal_attempts')) {
            return;
        }

        $columns = array_values(array_filter([
            $this->hasColumn('account_id') ? 'account_id' : null,
            $this->hasColumn('chat_id') ? 'chat_id' : null,
            $this->hasColumn('chat_product') ? 'chat_product' : null,
            $this->hasColumn('chat_stage') ? 'chat_stage' : null,
            $this->hasColumn('contact_name') ? 'contact_name' : null,
            $this->hasColumn('contact_phone') ? 'contact_phone' : null,
            $this->hasColumn('contact_email') ? 'contact_email' : null,
            $this->hasColumn('contact_cpf') ? 'contact_cpf' : null,
            $this->hasColumn('contact_birth_date') ? 'contact_birth_date' : null,
            $this->hasColumn('contact_mother_name') ? 'contact_mother_name' : null,
            $this->hasColumn('session_campaign') ? 'session_campaign' : null,
            $this->hasColumn('session_inbox_phone_number') ? 'session_inbox_phone_number' : null,
            $this->hasColumn('session_product_being_processed') ? 'session_product_being_processed' : null,
            $this->hasColumn('tags') ? 'tags' : null,
            $this->hasColumn('proposal_id') ? 'proposal_id' : null,
            $this->hasColumn('proposal_number') ? 'proposal_number' : null,
            $this->hasColumn('proposal_status') ? 'proposal_status' : null,
            $this->hasColumn('bank') ? 'bank' : null,
            $this->hasColumn('proposal_product') ? 'proposal_product' : null,
            $this->hasColumn('liquid_value') ? 'liquid_value' : null,
            $this->hasColumn('gross_value') ? 'gross_value' : null,
            $this->hasColumn('number_of_payments') ? 'number_of_payments' : null,
            $this->hasColumn('installment_value') ? 'installment_value' : null,
            $this->hasColumn('table_name') ? 'table_name' : null,
            $this->hasColumn('table_id') ? 'table_id' : null,
            $this->hasColumn('formalization_link') ? 'formalization_link' : null,
        ]));

        if ($columns === []) {
            return;
        }

        Schema::table('vendeai_newcorban_proposal_attempts', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
    }

    private function hasColumn(string $column): bool
    {
        return Schema::hasColumn('vendeai_newcorban_proposal_attempts', $column);
    }
};
