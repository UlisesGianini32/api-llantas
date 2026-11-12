<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class AutomotiveLocation extends AutopartesModel
{
    protected $table = 'automotive_locations';

    protected $fillable = ['branch_id', 'code', 'name', 'description', 'is_active'];

    protected static function booted(): void
    {
        static::updating(function (self $location): void {
            if ($location->isDirty('branch_id')) {
                throw new InvalidArgumentException('La ubicación de Autopartes no puede reasignarse a otra sucursal.');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(AutomotiveInventoryBalance::class, 'automotive_location_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AutomotiveInventoryMovement::class, 'automotive_location_id');
    }
}
