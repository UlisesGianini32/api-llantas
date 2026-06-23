<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';

    protected $fillable = [
        'name',
        'ml',
        'sku',
        'official_store_id',
        'category_name',
        'category_id',
        'shopify_category_id',
        'shopify_category_name',
        'shopify_category_source',
        'price',
        'stock',
        'status_ml',
        'thumbnail',
        'permalink',
        'brand',
        'pictures',
        'description',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'pictures' => 'array',
    ];
}