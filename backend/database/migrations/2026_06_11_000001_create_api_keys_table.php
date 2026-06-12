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
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key_hash', 64)->unique();
            $table->string('plain_key', 255)->nullable();
            $table->string('user_id')->nullable();
            $table->string('status', 20)->default('active'); // active, inactive
            $table->decimal('total_budget', 12, 4)->default(10.0000); // in USD (default $10.00)
            $table->decimal('remaining_budget', 12, 4)->default(10.0000); // in USD (default $10.00)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
