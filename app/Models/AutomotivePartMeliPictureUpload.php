<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartMeliPictureUpload extends Model
{
    protected $guarded = [];
    protected $casts = ['sanitized_response' => 'array', 'uploaded_at' => 'datetime'];
    public function publication(): BelongsTo { return $this->belongsTo(AutomotivePartMeliPublication::class, 'publication_id'); }
    public function media(): BelongsTo { return $this->belongsTo(AutomotivePartMedia::class, 'automotive_part_media_id'); }
}
