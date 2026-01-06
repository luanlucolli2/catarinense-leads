<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_authorizations', function (Blueprint $table) {
            if (!Schema::hasColumn('bank_authorizations', 'connection_token')) {
                $table->string('connection_token', 255)->nullable()->after('tracking_id');
            }
        });

        Schema::table('bank_authorizations', function (Blueprint $table) {
            // Índice para lookup do webhook: bank + status + phone + connection_token + id
            $table->index(['bank', 'status', 'phone', 'connection_token', 'id'], 'bank_auth_c6_lookup_idx');
        });

        Schema::table('inovachat_triages', function (Blueprint $table) {
            $table->index(['phone', 'connection_token'], 'triage_phone_conn_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inovachat_triages', function (Blueprint $table) {
            $table->dropIndex('triage_phone_conn_idx');
        });

        Schema::table('bank_authorizations', function (Blueprint $table) {
            $table->dropIndex('bank_auth_c6_lookup_idx');

            if (Schema::hasColumn('bank_authorizations', 'connection_token')) {
                $table->dropColumn('connection_token');
            }
        });
    }
};
