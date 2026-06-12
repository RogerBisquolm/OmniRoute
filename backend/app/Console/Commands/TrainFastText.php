<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class TrainFastText extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fasttext:train';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate training dataset and trigger FastText intent classifier retraining in the Gateway';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Generating training dataset for FastText...');

        // Training samples matching intent labels
        $samples = [
            // Code (English)
            "__label__code how to write a quicksort algorithm in python",
            "__label__code fix this runtime error in java",
            "__label__code database query optimizer in mariadb",
            "__label__code regex match email address syntax",
            "__label__code implement a linked list in rust",
            "__label__code kubernetes deployment yaml config file",
            // Code (English - Set 2)
            "__label__code write a quicksort implementation in python",
            "__label__code how do i code a binary search tree in java",
            "__label__code optimize mariadb database query execution plan",
            "__label__code regular expression for email address validation",
            "__label__code implement a doubly linked list in rust",
            "__label__code configure docker-compose for fastapi backend and redis cache",
            
            // Creative (English)
            "__label__creative write a science fiction story about mars",
            "__label__creative compose a romantic song in g major",
            "__label__creative brainstorm naming ideas for a fintech startup",
            "__label__creative draft an intro for a podcast on philosophy",
            "__label__creative design a D&D character sheet for a rogue",
            // Creative (English - Set 2)
            "__label__creative write a story about time travel adventures",
            "__label__creative compose a music sheet in g major scale",
            "__label__creative brainstorm catchy names for a technology startup",
            "__label__creative draft an intro hook for a philosophy podcast",
            "__label__creative write a bedtime story about little wolf cubs",
            
            // Support (English)
            "__label__support my login token expired help",
            "__label__support invoice was sent to the wrong address",
            "__label__support refund request policy terms",
            "__label__support reset my two factor authentication settings",
            "__label__support is there an active server outage today",
            // Support (English - Set 2)
            "__label__support my login session expired help me log in",
            "__label__support support ticket for password reset request",
            "__label__support billing invoice was sent to the wrong email address",
            "__label__support request refund for failed transaction charge",
            "__label__support how can i cancel my monthly premium subscription",
            
            // General (English)
            "__label__general what is the definition of photosynthesis",
            "__label__general distance between moon and sun",
            "__label__general list of top tourist places in switzerland",
            "__label__general how to cook scrambled eggs",
            "__label__general tell me a dad joke",
            // General (English - Set 2)
            "__label__general what is the definition of photosynthesis in plants",
            "__label__general distance between planet earth and the moon",
            "__label__general name the capital city of switzerland",
            "__label__general search for a chocolate chip cookies recipe",
            "__label__general tell me a funny dad joke"
        ];

        // Shared volume path in the container structure
        // /app/models/fasttext is mounted as a shared directory
        $sharedPath = '/app/models/fasttext/training_data.txt';
        
        $this->info("Writing training data to: {$sharedPath}");
        
        // Ensure path directory exists (in local filesystem mockup)
        $directory = dirname($sharedPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($sharedPath, implode("\n", $samples) . "\n");

        $this->info('Broadcasting training request to API Gateway via Redis Pub/Sub...');

        // Broadcast actions are handled dynamically in the gateway main listener
        Redis::publish('gateway_config_updates', json_encode([
            'action' => 'train_fasttext',
            'dataset_path' => $sharedPath
        ]));

        $this->info('Training trigger broadcasted successfully.');
        return 0;
    }
}
