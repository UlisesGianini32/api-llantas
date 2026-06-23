<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Llanta extends Model
{
    protected $fillable = [
        'sku',
        'marca',
        'medida',
        'descripcion',
        'costo',
        'precio_ML',
        'title_familyname',
        'MLM',
        'stock',
        'price_mode',
        'last_import_at',
        'official_store_id',
    ];

    protected $casts = [
        'last_import_at'  => 'datetime',
        'price_locked_at' => 'datetime',
    ];

    public function compuestos()
    {
        return $this->hasMany(ProductoCompuesto::class);
    }

    public function meliPublications()
    {
        return $this->hasMany(\App\Models\MeliPublication::class, 'sku', 'sku')
            ->orderByDesc('id');
    }

    /**
     * ✅ ÚLTIMA publicación (la que manda para el estatus)
     * IMPORTANTE: NO usar select() aquí para evitar "sku ambiguous"
     */
    public function latestMeliPublication()
    {
        return $this->hasOne(\App\Models\MeliPublication::class, 'sku', 'sku')
            ->latestOfMany('id');
    }

    public function getPrecioMlRealAttribute()
    {
        return $this->precio_ML ?? 0;
    }

    public function getCostoRealAttribute()
    {
        return $this->costo ?? 0;
    }

    public function getTituloRealAttribute()
    {
        return $this->title_familyname ?? '—';
    }
}