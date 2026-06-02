<?php

namespace App\Modules\Workflow\Models;

use App\Models\User;
use App\Modules\Documents\Models\Document;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentReview extends Model
{
    use HasUuids;

    const STATUS_IN_REVIEW = 'in_review';
    const STATUS_APPROVED  = 'approved';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_ARCHIVED  = 'archived';

    protected $fillable = [
        'document_id',
        'organization_id',
        'template_id',
        'started_by',
        'status',
        'current_stage_index',
        'due_at',
    ];

    protected function casts(): array
    {
        return [
            'current_stage_index' => 'integer',
            'due_at'              => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'template_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ReviewStage::class, 'review_id')->orderBy('stage_index');
    }

    public function currentStage(): ?ReviewStage
    {
        return $this->stages()->where('stage_index', $this->current_stage_index)->first();
    }
}
