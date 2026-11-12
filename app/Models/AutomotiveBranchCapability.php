<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotiveBranchCapability extends AutopartesModel
{
    protected $table = 'automotive_branch_capabilities';

    protected $fillable = ['branch_user_id', 'capability'];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranchMembership::class, 'branch_user_id');
    }
}
