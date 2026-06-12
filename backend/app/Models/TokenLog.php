<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TokenLog extends Model
{
    use HasFactory;

    // Map to the existing table created by the gateway migration
    protected $table = 'token_logs';

    // Disable default timestamps since the table only has created_at
    public $timestamps = false;

    protected $fillable = [
        'api_key_id',
        'user_id',
        'intent',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'latency_ms',
        'cost_usd',
    ];

    /**
     * Get the API Key model this log belongs to.
     */
    public function apiKey()
    {
        return $this->belongsTo(ApiKey::class, 'api_key_id');
    }
}
