<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPairs extends Model
{
    protected $fillable=[
        'user_id',
        'setting',
    ];
}
