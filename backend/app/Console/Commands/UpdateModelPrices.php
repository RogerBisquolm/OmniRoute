<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use App\Models\ModelPrice;

class UpdateModelPrices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pricing:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch live model pricing from OpenRouter API, update database, and sync to Redis gateway cache';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Fetching model pricing from OpenRouter...');

        try {
            $response = Http::timeout(30)->get('https://openrouter.ai/api/v1/models');
            
            if (!$response->successful()) {
                $this->error('Failed to fetch pricing from OpenRouter. Status: ' . $response->status());
                return 1;
            }

            $data = $response->json();
            if (!isset($data['data']) || !is_array($data['data'])) {
                $this->error('Invalid response format from OpenRouter.');
                return 1;
            }

            $models = $data['data'];
            $this->info('Fetched ' . count($models) . ' models. Processing...');

            // Alias map from OpenRouter model IDs to our internal model names
            $aliasMap = [
                'anthropic/claude-3.5-sonnet' => ['claude-3-5-sonnet-20240620', 'claude-3.5-sonnet', 'claude-3-5-sonnet'],
                'openai/gpt-4o-mini' => ['gpt-4o-mini'],
                'google/gemini-flash-1.5' => ['gemini-1.5-flash', 'gemini-flash-1.5'],
                'google/gemini-1.5-flash' => ['gemini-1.5-flash', 'gemini-flash-1.5'],
                'openai/gpt-4o' => ['gpt-4o'],
                'anthropic/claude-3-opus' => ['claude-3-opus', 'claude-3-opus-20240229'],
                'anthropic/claude-3-haiku' => ['claude-3-haiku', 'claude-3-haiku-20240307'],
            ];

            $count = 0;
            foreach ($models as $model) {
                $modelId = $model['id'] ?? null;
                if (!$modelId) {
                    continue;
                }

                $pricing = $model['pricing'] ?? null;
                if (!$pricing) {
                    continue;
                }

                // Pricing prompt & completion are cost per 1 token (or direct decimal from OpenRouter)
                $inputPrice = floatval($pricing['prompt'] ?? 0.0);
                $outputPrice = floatval($pricing['completion'] ?? 0.0);

                // Determine provider
                $parts = explode('/', $modelId);
                $provider = count($parts) > 1 ? $parts[0] : 'unknown';

                // 1. Update/Create main model entry
                ModelPrice::updateOrCreate(
                    ['model_name' => $modelId],
                    [
                        'provider' => $provider,
                        'input_price_per_token' => $inputPrice,
                        'output_price_per_token' => $outputPrice,
                    ]
                );
                $count++;

                // 2. Update/Create aliases if matched
                if (isset($aliasMap[$modelId])) {
                    foreach ($aliasMap[$modelId] as $alias) {
                        ModelPrice::updateOrCreate(
                            ['model_name' => $alias],
                            [
                                'provider' => $provider,
                                'input_price_per_token' => $inputPrice,
                                'output_price_per_token' => $outputPrice,
                            ]
                        );
                        $count++;
                    }
                }
            }

            $this->info("Successfully updated {$count} model price entries in the database.");

            // 3. Sync all database model prices to Redis Hash
            $this->info('Synchronizing pricing to Redis gateway cache...');
            
            $allPrices = ModelPrice::all();
            $redisData = [];
            foreach ($allPrices as $price) {
                $redisData[$price->model_name] = json_encode([
                    'input' => floatval($price->input_price_per_token),
                    'output' => floatval($price->output_price_per_token),
                    'provider' => $price->provider,
                ]);
            }

            if (!empty($redisData)) {
                // Delete old keys first
                Redis::del('gateway:model_prices');
                Redis::hmset('gateway:model_prices', $redisData);
                $this->info('Redis gateway pricing cache sync complete.');
            } else {
                $this->warn('No model prices found to sync to Redis.');
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('An error occurred while updating model prices: ' . $e->getMessage());
            return 1;
        }
    }
}
