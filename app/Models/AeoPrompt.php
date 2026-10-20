<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AeoPrompt extends Model
{
    protected $table = 'aeo_prompts';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = [
        'raw' => 'array',
    ];
}
