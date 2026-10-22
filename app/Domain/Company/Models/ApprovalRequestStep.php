<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequestStep extends Model
{
    use HasUuids;

    protected $fillable = ['approval_request_id', 'approval_flow_step_id', 'company_role_id', 'sequence', 'required_approvals', 'approved_count', 'status', 'acted_by', 'acted_at', 'notes'];
    protected $casts = ['acted_at' => 'datetime'];
    public function role(): BelongsTo { return $this->belongsTo(CompanyRole::class, 'company_role_id'); }
}
