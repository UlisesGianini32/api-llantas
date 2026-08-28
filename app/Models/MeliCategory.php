<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeliCategory extends Model
{
    protected $fillable = [
        'category_id', 'name', 'parent_id', 'root_category_id', 'path_from_root', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'path_from_root' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
