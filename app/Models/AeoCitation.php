<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AeoCitation extends Model
{
    protected $table = 'aeo_citations';
    protected $guarded = [];
    protected $casts = [
        'raw' => 'array',
    ];
}
