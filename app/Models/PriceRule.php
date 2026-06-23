<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceRule extends Model
{
    protected $fillable = [
        'rule_set',
        'scope',
        'formula',
        'active',
    ];
}
