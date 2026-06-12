<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Redis;
use App\Models\ApiKey;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('budget:reset', function () {
    $keys = ApiKey::onWriteConnection()->where('budget_type', 'monthly')->get();
    $count = 0;
    foreach ($keys as $key) {
        /** @var ApiKey $key */
        $key->remaining_budget = $key->total_budget;
        $key->save();

        // Securely cache key mapping in Redis using key_hash as the Redis key
        $redisKey = "auth:key:{$key->key_hash}";
        Redis::set($redisKey, json_encode([
            'api_key_id' => (string) $key->id,
            'user_id' => $key->user_id,
            'active' => $key->status === 'active',
            'remaining_budget' => (float) $key->remaining_budget,
            'allowed_rules' => $key->allowed_rules
        ]));
        Redis::expire($redisKey, 86400);
        $count++;
    }
    $this->info("Successfully reset budget for {$count} monthly API keys.");
})->purpose('Reset remaining budget for monthly API keys');

Schedule::command('budget:reset')->monthlyOn(1, '00:00');
Schedule::command('pricing:update')->daily();

