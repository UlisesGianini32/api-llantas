<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliLabelPrint extends Model
{
    public const TYPE_PRODUCT = 'product';

    public const TYPE_PACKAGE = 'package';

    public const STATUS_ANALYZED = 'analyzed';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'labels_count' => 'integer',
            'zpl_blocks_count' => 'integer',
            'physical_labels_count' => 'integer',
            'created_by' => 'integer',
            'printed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, MeliLabelPrint> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
