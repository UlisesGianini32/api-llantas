<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeliClaimAttachmentUpload extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['success' => 'boolean']; }
}
