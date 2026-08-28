<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartMediaEvent extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
    public function media(): BelongsTo { return $this->belongsTo(AutomotivePartMedia::class, 'automotive_part_media_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
