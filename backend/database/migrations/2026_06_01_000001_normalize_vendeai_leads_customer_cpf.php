<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE vendeai_leads
            SET customer_cpf = NULLIF(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(customer_cpf, '.', ''),
                        '-', ''),
                    ' ', ''),
                '/', ''),
            '')
            WHERE customer_cpf IS NOT NULL
              AND customer_cpf <> ''
        SQL);
    }

    public function down(): void
    {
    }
};
