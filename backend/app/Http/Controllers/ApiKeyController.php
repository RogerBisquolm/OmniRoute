<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class ApiKeyController extends Controller
{
    /**
     * Display a listing of API keys.
     */
    public function index()
    {
        return response()->json(ApiKey::onWriteConnection()->get());
    }

    /**
     * Store a newly created API key in storage and cache it in Redis.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'user_id' => 'nullable|string|max:255',
            'total_budget' => 'nullable|numeric|min:0',
            'budget_type' => 'nullable|string|in:one_time,monthly',
            'allowed_rules' => 'nullable|array',
            'allowed_rules.*' => 'integer|exists:routing_rules,id'
        ]);

        $name = $request->input('name');
        $userId = $request->input('user_id');
        $budget = (float) $request->input('total_budget', 10.0);
        $budgetType = $request->input('budget_type', 'one_time');
        $allowedRules = $request->input('allowed_rules');

        // Generate key and database entry
        $result = ApiKey::generate($name, $userId, $budget, $allowedRules, $budgetType);
        $plainKey = $result['plain_key'];
        $apiKey = $result['model'];

        // Securely cache key mapping in Redis using key_hash as the Redis key
        $redisKey = "auth:key:{$apiKey->key_hash}";
        Redis::set($redisKey, json_encode([
            'api_key_id' => (string) $apiKey->id,
            'user_id' => $apiKey->user_id,
            'active' => true,
            'remaining_budget' => (float) $apiKey->remaining_budget,
            'allowed_rules' => $apiKey->allowed_rules
        ]));
        // Set TTL of 24 hours
        Redis::expire($redisKey, 86400);

        return response()->json([
            'message' => 'API Key created successfully. Copy the plain key now; it will not be shown again.',
            'plain_key' => $plainKey,
            'details' => $apiKey
        ], 201);
    }

    /**
     * Update the specified API key status or budget in DB and Redis.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'status' => 'nullable|string|in:active,inactive',
            'total_budget' => 'nullable|numeric|min:0',
            'remaining_budget' => 'nullable|numeric|min:0',
            'budget_type' => 'nullable|string|in:one_time,monthly',
            'allowed_rules' => 'nullable|array',
            'allowed_rules.*' => 'integer|exists:routing_rules,id'
        ]);

        $apiKey = ApiKey::findOrFail($id);

        if ($request->has('status')) {
            $apiKey->status = $request->input('status');
        }

        if ($request->has('total_budget')) {
            $oldTotal = (float) $apiKey->total_budget;
            $newTotal = (float) $request->input('total_budget');
            $apiKey->total_budget = $newTotal;

            // If remaining_budget is not explicitly updated, adjust it by the difference
            if (!$request->has('remaining_budget')) {
                $diff = $newTotal - $oldTotal;
                $apiKey->remaining_budget = max(0.0, (float) $apiKey->remaining_budget + $diff);
            }
        }

        if ($request->has('remaining_budget')) {
            $apiKey->remaining_budget = (float) $request->input('remaining_budget');
        }

        if ($request->has('budget_type')) {
            $apiKey->budget_type = $request->input('budget_type');
        }

        if ($request->has('allowed_rules')) {
            $apiKey->allowed_rules = $request->input('allowed_rules');
        }

        $apiKey->save();

        // Update Redis cache entry
        $redisKey = "auth:key:{$apiKey->key_hash}";
        Redis::set($redisKey, json_encode([
            'api_key_id' => (string) $apiKey->id,
            'user_id' => $apiKey->user_id,
            'active' => $apiKey->status === 'active',
            'remaining_budget' => (float) $apiKey->remaining_budget,
            'allowed_rules' => $apiKey->allowed_rules
        ]));
        Redis::expire($redisKey, 86400);

        return response()->json([
            'message' => 'API Key updated successfully.',
            'details' => $apiKey
        ]);
    }

    /**
     * Remove the specified API key from DB and Redis.
     */
    public function destroy(string $id)
    {
        $apiKey = ApiKey::findOrFail($id);

        // Delete from Redis cache
        Redis::del("auth:key:{$apiKey->key_hash}");
        
        $apiKey->delete();

        return response()->json([
            'message' => 'API Key deleted successfully.'
        ]);
    }
}
