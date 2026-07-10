<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameTable('clt_consult_jobs', 'facta_clt_consult_jobs');
        $this->renameTable('clt_snapshots', 'facta_clt_snapshots');
        $this->renameTable('clt_pre_authorizations', 'facta_clt_pre_authorizations');
        $this->renameTable('clt_job_http_counters', 'facta_clt_job_http_counters');
    }

    public function down(): void
    {
        $this->renameTable('facta_clt_job_http_counters', 'clt_job_http_counters');
        $this->renameTable('facta_clt_pre_authorizations', 'clt_pre_authorizations');
        $this->renameTable('facta_clt_snapshots', 'clt_snapshots');
        $this->renameTable('facta_clt_consult_jobs', 'clt_consult_jobs');
    }

    private function renameTable(string $from, string $to): void
    {
        if (! Schema::hasTable($from) || Schema::hasTable($to)) {
            return;
        }

        Schema::rename($from, $to);
    }
};
