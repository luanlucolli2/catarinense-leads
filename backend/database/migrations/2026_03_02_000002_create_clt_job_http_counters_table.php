<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clt_job_http_counters')) {
            return;
        }

        Schema::create('clt_job_http_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->string('endpoint', 120);
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('response_count')->default(0);
            $table->unsignedInteger('status_2xx_count')->default(0);
            $table->unsignedInteger('status_4xx_count')->default(0);
            $table->unsignedInteger('status_5xx_count')->default(0);
            $table->unsignedInteger('status_other_count')->default(0);
            $table->unsignedInteger('exception_count')->default(0);
            $table->unsignedInteger('timeout_count')->default(0);
            $table->unsignedInteger('connection_exception_count')->default(0);
            $table->unsignedInteger('no_response_count')->default(0);
            $table->timestamps();

            $table->unique(['job_id', 'endpoint'], 'clt_job_http_counters_job_endpoint_unique');
            $table->index('job_id', 'clt_job_http_counters_job_idx');
            $table->index('endpoint', 'clt_job_http_counters_endpoint_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clt_job_http_counters');
    }
};

