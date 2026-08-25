<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartMeliPublicationEvent extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
    public function publication(): BelongsTo { return $this->belongsTo(AutomotivePartMeliPublication::class, 'publication_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
