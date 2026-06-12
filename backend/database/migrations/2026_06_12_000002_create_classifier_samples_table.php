<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('classifier_samples', function (Blueprint $table) {
            $table->id();
            $table->string('intent')->index();
            $table->text('sample_text');
            $table->timestamps();
        });

        // Seed default training samples
        $samples = [
            // Code (English)
            ['intent' => 'code', 'sample_text' => 'Write a quicksort algorithm in python'],
            ['intent' => 'code', 'sample_text' => 'How to debug a segment fault in C++'],
            ['intent' => 'code', 'sample_text' => 'SQL query to select all users with active status'],
            ['intent' => 'code', 'sample_text' => 'Implement a binary search tree in Golang'],
            ['intent' => 'code', 'sample_text' => 'Fix this syntax error in typescript'],
            ['intent' => 'code', 'sample_text' => 'docker-compose configuration for fastapi and redis'],
            ['intent' => 'code', 'sample_text' => 'git command to squash commits'],
            ['intent' => 'code', 'sample_text' => 'How do I write a web scraper in Node.js?'],
            ['intent' => 'code', 'sample_text' => 'write a python script to parse csv data'],
            ['intent' => 'code', 'sample_text' => 'what is the difference between interface and abstract class?'],
            // Code (English - Set 2)
            ['intent' => 'code', 'sample_text' => 'write a quicksort implementation in python'],
            ['intent' => 'code', 'sample_text' => 'how do i code a binary search tree in java'],
            ['intent' => 'code', 'sample_text' => 'optimize mariadb database query execution plan'],
            ['intent' => 'code', 'sample_text' => 'regular expression for email address validation'],
            ['intent' => 'code', 'sample_text' => 'implement a doubly linked list in rust'],
            ['intent' => 'code', 'sample_text' => 'configure docker-compose for fastapi backend and redis cache'],
            ['intent' => 'code', 'sample_text' => 'how to fix typescript compilation errors'],
            ['intent' => 'code', 'sample_text' => 'git command for squashing last three commits'],
            ['intent' => 'code', 'sample_text' => 'building a custom web scraper with node.js'],
            
            // Creative (English)
            ['intent' => 'creative', 'sample_text' => 'Write a short story about a time traveler who gets stuck in 1920'],
            ['intent' => 'creative', 'sample_text' => 'Compose a poem about ocean waves during a thunderstorm'],
            ['intent' => 'creative', 'sample_text' => 'Generate 10 catchy names for a futuristic coffee shop'],
            ['intent' => 'creative', 'sample_text' => 'Draft a screenplay scene where two spies meet in a museum'],
            ['intent' => 'creative', 'sample_text' => 'Brainstorm marketing ideas for an eco-friendly water bottle'],
            ['intent' => 'creative', 'sample_text' => 'Write a song about coding late at night'],
            ['intent' => 'creative', 'sample_text' => 'help me write a blog post about artificial intelligence in art'],
            ['intent' => 'creative', 'sample_text' => 'Create a description for a fantasy game world'],
            ['intent' => 'creative', 'sample_text' => 'Write an email script to pitch a business idea'],
            // Creative (English - Set 2)
            ['intent' => 'creative', 'sample_text' => 'write a story about time travel adventures'],
            ['intent' => 'creative', 'sample_text' => 'compose a music sheet in g major scale'],
            ['intent' => 'creative', 'sample_text' => 'brainstorm catchy names for a technology startup'],
            ['intent' => 'creative', 'sample_text' => 'draft an intro hook for a philosophy podcast'],
            ['intent' => 'creative', 'sample_text' => 'write a bedtime story about little wolf cubs'],
            ['intent' => 'creative', 'sample_text' => 'write a beautiful poem about autumn leaves'],
            ['intent' => 'creative', 'sample_text' => 'creative ideas for a school art project'],
            ['intent' => 'creative', 'sample_text' => 'write a short screenplay for a detective investigation scene'],
            ['intent' => 'creative', 'sample_text' => 'make up a fantasy story about a mysterious forest and wolves'],
 
            // Support (English)
            ['intent' => 'support', 'sample_text' => 'I forgot my account password, how can I reset it?'],
            ['intent' => 'support', 'sample_text' => 'Where can I view and download my billing invoices?'],
            ['intent' => 'support', 'sample_text' => 'My subscription payment failed, please help.'],
            ['intent' => 'support', 'sample_text' => 'How do I cancel my monthly subscription plan?'],
            ['intent' => 'support', 'sample_text' => 'My account is locked, how can I unlock it?'],
            ['intent' => 'support', 'sample_text' => 'I need to update my email address on my profile.'],
            ['intent' => 'support', 'sample_text' => 'Can I request a refund for my last transaction?'],
            ['intent' => 'support', 'sample_text' => 'I am experiencing high latency, is there a server outage?'],
            ['intent' => 'support', 'sample_text' => 'Contact support department phone number'],
            // Support (English - Set 2)
            ['intent' => 'support', 'sample_text' => 'my login session expired help me log in'],
            ['intent' => 'support', 'sample_text' => 'support ticket for password reset request'],
            ['intent' => 'support', 'sample_text' => 'billing invoice was sent to the wrong email address'],
            ['intent' => 'support', 'sample_text' => 'request refund for failed transaction charge'],
            ['intent' => 'support', 'sample_text' => 'how can i cancel my monthly premium subscription'],
            ['intent' => 'support', 'sample_text' => 'unlock my locked user account'],
            ['intent' => 'support', 'sample_text' => 'customer service support hotline phone number'],
            ['intent' => 'support', 'sample_text' => 'check if there is an active server outage today'],
 
            // General (English)
            ['intent' => 'general', 'sample_text' => 'What is the capital city of Switzerland?'],
            ['intent' => 'general', 'sample_text' => 'How far is the Earth from the Moon?'],
            ['intent' => 'general', 'sample_text' => 'Who wrote the novel Pride and Prejudice?'],
            ['intent' => 'general', 'sample_text' => 'Can you explain photosynthesis in simple terms?'],
            ['intent' => 'general', 'sample_text' => 'Give me a recipe for chocolate chip cookies.'],
            ['intent' => 'general', 'sample_text' => 'What is the speed of light?'],
            ['intent' => 'general', 'sample_text' => 'Tell me a funny joke.'],
            ['intent' => 'general', 'sample_text' => 'What is the weather like in Tokyo in June?'],
            ['intent' => 'general', 'sample_text' => 'How many elements are in the periodic table?'],
            ['intent' => 'general', 'sample_text' => 'What are some tips for visiting Switzerland?'],
            // General (English - Set 2)
            ['intent' => 'general', 'sample_text' => 'what is the definition of photosynthesis in plants'],
            ['intent' => 'general', 'sample_text' => 'distance between planet earth and the moon'],
            ['intent' => 'general', 'sample_text' => 'name the capital city of switzerland'],
            ['intent' => 'general', 'sample_text' => 'search for a chocolate chip cookies recipe'],
            ['intent' => 'general', 'sample_text' => 'tell me a funny dad joke'],
            ['intent' => 'general', 'sample_text' => 'what is the current weather forecast for tokyo'],
            ['intent' => 'general', 'sample_text' => 'how many chemical elements are in the periodic table'],
            ['intent' => 'general', 'sample_text' => 'travel tips for planning a vacation to italy']
        ];

        $now = now();
        foreach ($samples as &$sample) {
            $sample['created_at'] = $now;
            $sample['updated_at'] = $now;
        }

        DB::table('classifier_samples')->insert($samples);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classifier_samples');
    }
};
