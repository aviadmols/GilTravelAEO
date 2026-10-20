<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AeoPromptRun extends Model
{
    protected $table = 'aeo_prompt_runs';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = [
        'raw' => 'array',
    ];
}
