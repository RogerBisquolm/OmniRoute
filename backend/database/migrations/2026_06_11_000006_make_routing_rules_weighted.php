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
        Schema::table('routing_rules', function (Blueprint $table) {
            // Drop unique constraint on intent column
            // In Laravel, the unique index name typically matches the table name + column name + unique suffix
            $table->dropUnique('routing_rules_intent_unique');
            
            // Add weight column
            $table->integer('weight')->default(100)->after('api_key_env');
            
            // Add unique index on intent and model combination to avoid duplicate models for the same intent
            $table->unique(['intent', 'model'], 'routing_rules_intent_model_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routing_rules', function (Blueprint $table) {
            $table->dropUnique('routing_rules_intent_model_unique');
            $table->dropColumn('weight');
            $table->unique('intent', 'routing_rules_intent_unique');
        });
    }
};
