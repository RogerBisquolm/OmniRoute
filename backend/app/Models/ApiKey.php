<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'key_hash',
        'plain_key',
        'user_id',
        'status',
        'total_budget',
        'remaining_budget',
        'budget_type',
        'allowed_rules',
    ];

    protected $casts = [
        'allowed_rules' => 'array',
    ];

    /**
     * Generate a new unique plain-text API key and return it.
     * The SHA-256 hash will be stored in the database.
     *
     * @param string $name
     * @param string|null $userId
     * @param float $budget
     * @param array|null $allowedRules
     * @param string $budgetType
     * @return array Contains ['plain_key' => 'sk-omni-...', 'model' => ApiKey]
     */
    public static function generate(string $name, ?string $userId = null, float $budget = 10.0, ?array $allowedRules = null, string $budgetType = 'one_time'): array
    {
        $plainKey = 'sk-omni-' . Str::random(40);
        $keyHash = hash('sha256', $plainKey);

        $apiKey = self::create([
            'name' => $name,
            'key_hash' => $keyHash,
            'plain_key' => $plainKey,
            'user_id' => $userId,
            'status' => 'active',
            'total_budget' => $budget,
            'remaining_budget' => $budget,
            'budget_type' => $budgetType,
            'allowed_rules' => $allowedRules,
        ]);

        return [
            'plain_key' => $plainKey,
            'model' => $apiKey
        ];
    }

    /**
     * Check if the API key is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the API key has remaining budget.
     */
    public function hasBudget(): bool
    {
        return $this->remaining_budget > 0;
    }
}
