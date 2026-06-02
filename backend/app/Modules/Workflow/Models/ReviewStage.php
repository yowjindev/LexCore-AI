<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewStage extends Model
{
    use HasUuids;

    const DECISION_PENDING  = 'pending';
    const DECISION_APPROVED = 'approved';
    const DECISION_REJECTED = 'rejected';

    protected $fillable = [
        'review_id',
        'stage_index',
        'stage_name',
        'approver_role',
        'decided_by',
        'decision',
        'comment',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'stage_index' => 'integer',
            'decided_at'  => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(DocumentReview::class, 'review_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
