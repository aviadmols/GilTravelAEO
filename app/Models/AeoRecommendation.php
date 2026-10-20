<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AeoRecommendation extends Model
{
    protected $table = 'aeo_recommendations';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = [
        'raw' => 'array',
    ];
}
