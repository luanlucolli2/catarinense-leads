<?php
/* database/migrations/2025_07_19_000002_add_vendor_id_to_lead_contracts.php */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_contracts', function (Blueprint $table) {
            $table->foreignId('vendor_id')
                ->nullable()
                ->after('lead_id')
                ->constrained('vendors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lead_contracts', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
            $table->dropColumn('vendor_id');
        });
    }
};
