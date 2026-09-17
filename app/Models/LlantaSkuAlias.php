<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlantaSkuAlias extends Model
{
    protected $fillable = [
        'llanta_id',
        'sku_alias',
        'source',
    ];

    public function llanta()
    {
        return $this->belongsTo(Llanta::class);
    }
}
