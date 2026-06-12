<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoutingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'intent',
        'provider',
        'model',
        'url',
        'api_key_env',
        'fallback_provider',
        'fallback_model',
        'fallback_url',
        'fallback_api_key_env',
        'weight',
    ];
}
