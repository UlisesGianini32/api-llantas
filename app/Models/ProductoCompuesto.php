<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoCompuesto extends Model
{
    protected $table = 'producto_compuestos';

    protected $fillable = [
        'llanta_id',
        'sku',
        'tipo',
        'stock',
        'descripcion',
        'title_familyname',
        'costo',
        'precio_ML',
        'MLM',
        'price_mode', // ✅ IMPORTANTÍSIMO
    ];

    protected $appends = [
        'precio_ml_real',
        'costo_real',
        'titulo_real',
    ];

    public function llanta()
    {
        return $this->belongsTo(Llanta::class);
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
	public function meliPublications()
{
    return $this->hasMany(\App\Models\MeliPublication::class, 'sku', 'sku');
}
}
