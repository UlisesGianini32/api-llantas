<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotiveBranchMembership extends AutopartesModel
{
    protected $table = 'automotive_branch_users';

    protected $fillable = ['branch_id', 'user_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(AutomotiveBranchCapability::class, 'branch_user_id');
    }
}
