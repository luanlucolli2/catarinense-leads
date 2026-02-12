<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('c6_authorization_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('cpf', 11);
            $table->text('link');
            $table->timestamp('generated_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'generated_at']);
            $table->index(['user_id', 'expires_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('c6_authorization_links');
    }
};
