<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        \App\Models\User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // Seed default gateway routing rules
        $rules = [
            // Code Intent: 70% Claude, 30% GPT-4o
            [
                'intent' => 'code',
                'provider' => 'anthropic',
                'model' => 'claude-3-5-sonnet-20240620',
                'url' => 'https://api.anthropic.com/v1/messages',
                'api_key_env' => 'ANTHROPIC_API_KEY',
                'fallback_provider' => 'openai',
                'fallback_model' => 'gpt-4o-mini',
                'fallback_url' => 'https://api.openai.com/v1/chat/completions',
                'fallback_api_key_env' => 'OPENAI_API_KEY',
                'weight' => 70,
            ],
            [
                'intent' => 'code',
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'url' => 'https://api.openai.com/v1/chat/completions',
                'api_key_env' => 'OPENAI_API_KEY',
                'fallback_provider' => 'google',
                'fallback_model' => 'gemini-1.5-flash',
                'fallback_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
                'fallback_api_key_env' => 'GEMINI_API_KEY',
                'weight' => 30,
            ],
            // Creative Intent: 50% Claude, 50% GPT-4o-mini
            [
                'intent' => 'creative',
                'provider' => 'anthropic',
                'model' => 'claude-3-5-sonnet-20240620',
                'url' => 'https://api.anthropic.com/v1/messages',
                'api_key_env' => 'ANTHROPIC_API_KEY',
                'fallback_provider' => 'openai',
                'fallback_model' => 'gpt-4o-mini',
                'fallback_url' => 'https://api.openai.com/v1/chat/completions',
                'fallback_api_key_env' => 'OPENAI_API_KEY',
                'weight' => 50,
            ],
            [
                'intent' => 'creative',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'url' => 'https://api.openai.com/v1/chat/completions',
                'api_key_env' => 'OPENAI_API_KEY',
                'fallback_provider' => 'google',
                'fallback_model' => 'gemini-1.5-flash',
                'fallback_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
                'fallback_api_key_env' => 'GEMINI_API_KEY',
                'weight' => 50,
            ],
            // Support Intent: 100% GPT-4o-mini
            [
                'intent' => 'support',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'url' => 'https://api.openai.com/v1/chat/completions',
                'api_key_env' => 'OPENAI_API_KEY',
                'fallback_provider' => 'google',
                'fallback_model' => 'gemini-1.5-flash',
                'fallback_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
                'fallback_api_key_env' => 'GEMINI_API_KEY',
                'weight' => 100,
            ],
            // General Intent: 100% Gemini 1.5 Flash
            [
                'intent' => 'general',
                'provider' => 'google',
                'model' => 'gemini-1.5-flash',
                'url' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
                'api_key_env' => 'GEMINI_API_KEY',
                'fallback_provider' => 'openai',
                'fallback_model' => 'gpt-4o-mini',
                'fallback_url' => 'https://api.openai.com/v1/chat/completions',
                'fallback_api_key_env' => 'OPENAI_API_KEY',
                'weight' => 100,
            ]
        ];

        if (\App\Models\RoutingRule::count() === 0) {
            foreach ($rules as $rule) {
                \App\Models\RoutingRule::updateOrCreate(
                    ['intent' => $rule['intent'], 'model' => $rule['model']],
                    $rule
                );
            }
        }
    }
}
