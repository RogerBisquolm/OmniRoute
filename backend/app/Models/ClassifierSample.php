<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassifierSample extends Model
{
    protected $table = 'classifier_samples';

    protected $fillable = [
        'intent',
        'sample_text',
    ];
}
