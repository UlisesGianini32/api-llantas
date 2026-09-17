<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeliChatFlow extends Model
{
    protected $fillable = [
        'user_id',
        'order_id',
        'pack_id',
        'conversation_id',
        'message_id',
        'last_inbound_message_id',
        'buyer_id',
        'item_id',
        'sku',
        'menu_sent',
        'menu_sent_at',
        'last_option_selected',
        'last_option_selected_at',
        'requires_human',
        'requires_human_at',
        'product_pdf_url',
        'catalog_pdf_url',
        'invoice_url',
        'meta',
    ];

    protected $casts = [
        'menu_sent' => 'boolean',
        'menu_sent_at' => 'datetime',
        'last_option_selected_at' => 'datetime',
        'requires_human' => 'boolean',
        'requires_human_at' => 'datetime',
        'meta' => 'array',
    ];
}
