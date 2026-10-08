<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('api_token_id')->nullable()->after('contact_id')->constrained()->nullOnDelete();
            $table->string('idempotency_key')->nullable()->after('wamid');

            $table->unique(['api_token_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['api_token_id', 'idempotency_key']);
            $table->dropConstrainedForeignId('api_token_id');
            $table->dropColumn('idempotency_key');
        });

        Schema::dropIfExists('api_tokens');
    }
};
