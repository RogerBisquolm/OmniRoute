<?php

namespace App\Http\Controllers;

use App\Models\TokenLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get aggregated telemetry metrics for the dashboard home.
     */
    public function metrics()
    {
        // Total counters
        $summary = TokenLog::query()
            ->select(
                DB::raw('COUNT(*) as total_requests'),
                DB::raw('SUM(prompt_tokens) as total_prompt_tokens'),
                DB::raw('SUM(completion_tokens) as total_completion_tokens'),
                DB::raw('SUM(total_tokens) as total_tokens_used'),
                DB::raw('AVG(latency_ms) as avg_latency_ms'),
                DB::raw('SUM(cost_usd) as total_cost_usd')
            )
            ->first();

        // Intent breakdown distribution
        $intents = TokenLog::query()
            ->select('intent', DB::raw('COUNT(*) as count'))
            ->groupBy('intent')
            ->orderByDesc('count')
            ->get();

        // Model breakdown distribution
        $models = TokenLog::query()
            ->select('model', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_tokens) as tokens'))
            ->groupBy('model')
            ->orderByDesc('count')
            ->get();

        // Recent logs
        $recentLogs = TokenLog::query()
            ->select('id', 'api_key_id', 'intent', 'model', 'total_tokens', 'latency_ms', 'cost_usd', 'created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        // Fetch active API keys and routing rules for GUI listing from the master connection
        // to guarantee strong consistency and avoid any database replication lag issues.
        $apiKeys = \App\Models\ApiKey::onWriteConnection()->get();
        $routingRules = \App\Models\RoutingRule::onWriteConnection()->get();

        return response()->json([
            'summary' => [
                'total_requests' => (int) ($summary->total_requests ?? 0),
                'total_prompt_tokens' => (int) ($summary->total_prompt_tokens ?? 0),
                'total_completion_tokens' => (int) ($summary->total_completion_tokens ?? 0),
                'total_tokens_used' => (int) ($summary->total_tokens_used ?? 0),
                'avg_latency_ms' => round((float) ($summary->avg_latency_ms ?? 0), 2),
                'total_cost_usd' => round((float) ($summary->total_cost_usd ?? 0.0), 6),
            ],
            'intents' => $intents,
            'models' => $models,
            'recent_logs' => $recentLogs,
            'api_keys' => $apiKeys,
            'routing_rules' => $routingRules,
        ]);
    }

    /**
     * Get dynamic guardrail keywords from Redis.
     */
    public function getKeywords()
    {
        $keywords = \Illuminate\Support\Facades\Redis::smembers('gateway:unsafe_keywords');
        return response()->json($keywords ?: []);
    }

    /**
     * Add a dynamic guardrail keyword to Redis.
     */
    public function addKeyword(Request $request)
    {
        $request->validate([
            'keyword' => 'required|string|min:1'
        ]);

        $keyword = trim($request->input('keyword'));
        \Illuminate\Support\Facades\Redis::sadd('gateway:unsafe_keywords', $keyword);

        return response()->json(['message' => 'Keyword added successfully.']);
    }

    /**
     * Delete a dynamic guardrail keyword from Redis.
     */
    public function deleteKeyword(Request $request)
    {
        $request->validate([
            'keyword' => 'required|string|min:1'
        ]);

        $keyword = trim($request->input('keyword'));
        \Illuminate\Support\Facades\Redis::srem('gateway:unsafe_keywords', $keyword);

        return response()->json(['message' => 'Keyword deleted successfully.']);
    }

    /**
     * Clear the semantic cache by deleting all keys matching 'cache:*'.
     */
    public function clearCache()
    {
        $keys = \Illuminate\Support\Facades\Redis::keys('cache:*');
        
        if (!empty($keys)) {
            // Strip connection prefix if Redis::keys returns full raw keys but Redis::del applies it again
            // In phpredis/predis, Redis::keys returns prefixed keys. Let's make it robust by stripping prefix if present.
            $prefix = env('REDIS_PREFIX', 'laravel-database-');
            $cleanedKeys = array_map(function($key) use ($prefix) {
                if ($prefix && strpos($key, $prefix) === 0) {
                    return substr($key, strlen($prefix));
                }
                return $key;
            }, $keys);
            
            \Illuminate\Support\Facades\Redis::del($cleanedKeys);
        }

        return response()->json(['message' => 'Semantic cache cleared successfully.', 'count' => count($keys)]);
    }

    /**
     * Get rephrase configuration from Redis.
     */
    public function getRephraseConfig()
    {
        $enabled = \Illuminate\Support\Facades\Redis::get('gateway:rephrase_cache_enabled') ?: '0';
        $provider = \Illuminate\Support\Facades\Redis::get('gateway:rephrase_cache_provider') ?: 'ollama';
        $model = \Illuminate\Support\Facades\Redis::get('gateway:rephrase_cache_model') ?: 'phi3';
        $threshold = \Illuminate\Support\Facades\Redis::get('gateway:semantic_cache_threshold') ?: '0.10';
        
        return response()->json([
            'enabled' => $enabled === '1' || $enabled === 'true',
            'provider' => $provider,
            'model' => $model,
            'threshold' => (float) $threshold
        ]);
    }

    /**
     * Update rephrase configuration in Redis.
     */
    public function updateRephraseConfig(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
            'provider' => 'required|string|in:ollama,openai,google,anthropic',
            'model' => 'required|string|min:1',
            'threshold' => 'required|numeric|min:0.01|max:1.00'
        ]);

        $enabled = $request->input('enabled') ? '1' : '0';
        $provider = $request->input('provider');
        $model = trim($request->input('model'));
        $threshold = number_format((float) $request->input('threshold'), 2, '.', '');

        \Illuminate\Support\Facades\Redis::set('gateway:rephrase_cache_enabled', $enabled);
        \Illuminate\Support\Facades\Redis::set('gateway:rephrase_cache_provider', $provider);
        \Illuminate\Support\Facades\Redis::set('gateway:rephrase_cache_model', $model);
        \Illuminate\Support\Facades\Redis::set('gateway:semantic_cache_threshold', $threshold);

        // Also publish update via Redis Pub/Sub to notify the python proxy immediately!
        \Illuminate\Support\Facades\Redis::publish('gateway_config_updates', json_encode([
            'action' => 'update_rephrase_config',
            'rephrase_config' => [
                'enabled' => $request->input('enabled'),
                'provider' => $provider,
                'model' => $model,
                'threshold' => (float) $threshold
            ]
        ]));

        return response()->json(['message' => 'Rephrase configuration updated successfully.']);
    }

    /**
     * Get compressor configuration from Redis.
     */
    public function getCompressorConfig()
    {
        $method = \Illuminate\Support\Facades\Redis::get('gateway:compressor_method') ?: 'llmlingua';
        $ratio = \Illuminate\Support\Facades\Redis::get('gateway:compressor_ratio') ?: '0.70';
        $cavemanIntensity = \Illuminate\Support\Facades\Redis::get('gateway:compressor_caveman_intensity') ?: 'full';
        
        return response()->json([
            'method' => $method,
            'ratio' => (float) $ratio,
            'caveman_intensity' => $cavemanIntensity
        ]);
    }

    /**
     * Update compressor configuration in Redis.
     */
    public function updateCompressorConfig(Request $request)
    {
        $request->validate([
            'method' => 'required|string|in:llmlingua,rtk,caveman,stacked,rtk+llmlingua,disabled',
            'ratio' => 'required|numeric|min:0.01|max:1.00',
            'caveman_intensity' => 'required|string|in:lite,full,ultra'
        ]);

        $method = $request->input('method');
        $ratio = number_format((float) $request->input('ratio'), 2, '.', '');
        $cavemanIntensity = $request->input('caveman_intensity');

        \Illuminate\Support\Facades\Redis::set('gateway:compressor_method', $method);
        \Illuminate\Support\Facades\Redis::set('gateway:compressor_ratio', $ratio);
        \Illuminate\Support\Facades\Redis::set('gateway:compressor_caveman_intensity', $cavemanIntensity);

        // Publish update via Redis Pub/Sub to notify the python proxy
        \Illuminate\Support\Facades\Redis::publish('gateway_config_updates', json_encode([
            'action' => 'update_compressor_config',
            'compressor_config' => [
                'method' => $method,
                'ratio' => (float) $ratio,
                'caveman_intensity' => $cavemanIntensity
            ]
        ]));

        return response()->json(['message' => 'Compressor configuration updated successfully.']);
    }


    /**
     * Get pulled Ollama models.
     */
    public function getOllamaModels()
    {
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 5.0]);
            $response = $client->get('http://ollama:11434/api/tags');
            $data = json_decode($response->getBody()->getContents(), true);
            
            // Format models for the GUI
            $models = array_map(function($m) {
                // Size in bytes to GB
                $sizeGB = round(($m['size'] ?? 0) / (1024 * 1024 * 1024), 2);
                return [
                    'name' => $m['name'],
                    'size' => $sizeGB . ' GB'
                ];
            }, $data['models'] ?? []);
            
            return response()->json($models);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to fetch Ollama models: " . $e->getMessage());
            return response()->json(['error' => 'Failed to connect to local Ollama container.'], 500);
        }
    }

    /**
     * Pull a model from Ollama registry.
     */
    public function pullOllamaModel(Request $request)
    {
        $request->validate([
            'model' => 'required|string|min:1'
        ]);
        
        $model = trim($request->input('model'));
        
        try {
            // Pulling can take time, set timeout to 10 minutes (600s)
            $client = new \GuzzleHttp\Client(['timeout' => 600.0]);
            $response = $client->post('http://ollama:11434/api/pull', [
                'json' => [
                    'name' => $model,
                    'stream' => false
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            return response()->json($data);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to pull Ollama model '$model': " . $e->getMessage());
            return response()->json(['error' => 'Failed to pull model from Ollama: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete an Ollama model.
     */
    public function deleteOllamaModel(Request $request)
    {
        $request->validate([
            'model' => 'required|string|min:1'
        ]);
        
        $model = trim($request->input('model'));
        
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 10.0]);
            $response = $client->delete('http://ollama:11434/api/delete', [
                'json' => [
                    'name' => $model
                ]
            ]);
            
            return response()->json(['message' => "Model '$model' deleted successfully."]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to delete Ollama model '$model': " . $e->getMessage());
            return response()->json(['error' => 'Failed to delete Ollama model: ' . $e->getMessage()], 500);
        }
    }
}
