<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalFlowStep extends Model
{
    use HasUuids;

    protected $fillable = ['approval_flow_id', 'sequence', 'name', 'company_role_id', 'required_approvals'];
    public function role(): BelongsTo { return $this->belongsTo(CompanyRole::class, 'company_role_id'); }
}
