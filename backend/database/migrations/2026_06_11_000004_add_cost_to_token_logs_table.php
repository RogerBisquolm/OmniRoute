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
        if (!Schema::hasTable('token_logs')) {
            Schema::create('token_logs', function (Blueprint $table) {
                $table->id();
                $table->string('api_key_id');
                $table->string('user_id')->nullable();
                $table->string('intent', 100);
                $table->string('model', 100);
                $table->integer('prompt_tokens');
                $table->integer('completion_tokens');
                $table->integer('total_tokens');
                $table->integer('latency_ms');
                $table->timestamp('created_at')->useCurrent();
                
                $table->index('api_key_id');
                $table->index('created_at');
            });
        }

        Schema::table('token_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('token_logs', 'cost_usd')) {
                $table->decimal('cost_usd', 18, 12)->default(0.000000000000)->after('latency_ms');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_logs');
    }
};
