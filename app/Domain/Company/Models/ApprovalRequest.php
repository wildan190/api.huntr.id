<?php

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalRequest extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'approval_flow_id', 'subject_type', 'subject_id', 'amount', 'status', 'current_sequence', 'requested_by', 'completed_by', 'completed_at', 'metadata'];
    protected $casts = ['amount' => 'decimal:2', 'completed_at' => 'datetime', 'metadata' => 'array'];
    public function flow(): BelongsTo { return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id'); }
    public function steps(): HasMany { return $this->hasMany(ApprovalRequestStep::class)->orderBy('sequence'); }
}
