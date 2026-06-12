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
        Schema::create('routing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('intent', 100)->unique(); // code, creative, support, general
            $table->string('provider', 50); // openai, anthropic
            $table->string('model', 100); // e.g. gpt-4o-mini, claude-3-5-sonnet-20240620
            $table->string('url', 255); // API Endpoint URL
            $table->string('api_key_env', 512); // environment key name (e.g. OPENAI_API_KEY)
            $table->string('fallback_provider', 50)->nullable();
            $table->string('fallback_model', 100)->nullable();
            $table->string('fallback_url', 255)->nullable();
            $table->string('fallback_api_key_env', 512)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routing_rules');
    }
};
