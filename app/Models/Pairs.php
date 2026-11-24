<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pairs extends Model
{
    protected $fillable=[
        'symbol',
        'is_active',
    ];
}
