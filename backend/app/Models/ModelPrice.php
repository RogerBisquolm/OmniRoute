<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModelPrice extends Model
{
    use HasFactory;

    protected $table = 'model_prices';

    protected $fillable = [
        'model_name',
        'provider',
        'input_price_per_token',
        'output_price_per_token',
    ];

    protected $casts = [
        'input_price_per_token' => 'float',
        'output_price_per_token' => 'float',
    ];
}
