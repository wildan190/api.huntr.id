<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalFlow extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'name', 'subject_type', 'currency', 'min_amount', 'max_amount', 'is_active', 'created_by'];
    protected $casts = ['is_active' => 'boolean', 'min_amount' => 'decimal:2', 'max_amount' => 'decimal:2'];
    public function steps(): HasMany { return $this->hasMany(ApprovalFlowStep::class)->orderBy('sequence'); }
}
