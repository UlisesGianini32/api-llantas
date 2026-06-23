<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyscomProduct extends Model
{
    protected $table = 'syscom_products';

    protected $fillable = [
        'syscom_producto_id',
        'modelo',
        'titulo',
        'marca',
        'sat_key',
        'img_portada',
        'precio_lista',
        'precio_especial',
        'precio_descuento',
        'total_existencia',
        'existencia',
        'imagenes',
        'descripcion',
        'categorias',
        'raw_list',
        'raw_detail',
        'last_synced_at',
        'stock_hermosillo',
    ];

    public function meliQueues()
    {
        return $this->hasMany(SyscomMeliQueue::class, 'syscom_product_id');
    }

    protected $casts = [
        'stock_hermosillo' => 'integer',
        'precio_lista' => 'decimal:2',
        'precio_especial' => 'decimal:2',
        'precio_descuento' => 'decimal:2',
        'total_existencia' => 'integer',
        'existencia' => 'array',
        'imagenes' => 'array',
        'categorias' => 'array',
        'raw_list' => 'array',
        'raw_detail' => 'array',
        'last_synced_at' => 'datetime',
    ];
}
