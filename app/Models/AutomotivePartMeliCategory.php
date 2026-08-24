<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotivePartMeliCategory extends Model
{
    protected $fillable = [
        'site_id', 'category_id', 'name', 'domain_id', 'path_from_root',
        'settings', 'raw_payload', 'attributes_synced_at', 'synced_at',
    ];

    protected $casts = [
        'path_from_root' => 'array',
        'settings' => 'array',
        'raw_payload' => 'array',
        'attributes_synced_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function attributeRequirements(): HasMany
    {
        return $this->hasMany(AutomotivePartMeliAttributeRequirement::class);
    }
}
