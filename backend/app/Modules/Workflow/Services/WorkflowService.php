<?php

namespace App\Modules\Workflow\Services;

use App\Exceptions\ForbiddenException;
use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Documents\Models\Document;
use App\Modules\Workflow\Events\ReviewApproved;
use App\Modules\Workflow\Events\ReviewRejected;
use App\Modules\Workflow\Events\ReviewStarted;
use App\Modules\Workflow\Events\StageApproved;
use App\Modules\Workflow\Events\StageRejected;
use App\Modules\Workflow\Models\DocumentReview;
use App\Modules\Workflow\Models\ReviewStage;
use App\Modules\Workflow\Models\WorkflowTemplate;

class WorkflowService
{
    public function __construct(private readonly ReviewStatusManager $statusManager) {}

    public function startReview(Document $document, User $user, ?string $templateId = null): DocumentReview
    {
        $template = $templateId
            ? WorkflowTemplate::where('id', $templateId)->where('organization_id', $document->organization_id)->firstOrFail()
            : WorkflowTemplate::where('organization_id', $document->organization_id)->where('is_default', true)->first();

        $review = DocumentReview::create([
            'document_id'         => $document->id,
            'organization_id'     => $document->organization_id,
            'template_id'         => $template?->id,
            'started_by'          => $user->id,
            'status'              => DocumentReview::STATUS_IN_REVIEW,
            'current_stage_index' => 0,
        ]);

        // Materialise stages from template
        $stages = $template?->stages ?? [['name' => 'Review', 'approver_role' => 'admin']];
        foreach ($stages as $index => $stageDef) {
            ReviewStage::create([
                'review_id'     => $review->id,
                'stage_index'   => $index,
                'stage_name'    => $stageDef['name'] ?? "Stage {$index}",
                'approver_role' => $stageDef['approver_role'] ?? 'admin',
                'decision'      => ReviewStage::DECISION_PENDING,
            ]);
        }

        AuditLog::create([
            'organization_id' => $document->organization_id,
            'user_id'         => $user->id,
            'action'          => 'review.started',
            'auditable_type'  => 'document',
            'auditable_id'    => $document->id,
            'metadata'        => ['review_id' => $review->id],
        ]);

        ReviewStarted::dispatch($review);

        return $review->load('stages');
    }

    public function advanceStage(
        DocumentReview $review,
        User           $actor,
        string         $decision,   // 'approved' | 'rejected'
        ?string        $comment = null,
    ): DocumentReview {
        $stage = $review->currentStage();

        if ($stage === null) {
            throw new \RuntimeException('No current stage found for review.');
        }

        // Role check — actor must have the required approver role
        if (! $actor->hasRole($stage->approver_role) && ! $actor->hasRole('superadmin')) {
            throw new ForbiddenException(
                "Only '{$stage->approver_role}' or superadmin can decide this stage."
            );
        }

        $stage->update([
            'decision'   => $decision,
            'decided_by' => $actor->id,
            'comment'    => $comment,
            'decided_at' => now(),
        ]);

        AuditLog::create([
            'organization_id' => $review->organization_id,
            'user_id'         => $actor->id,
            'action'          => "review.stage.{$decision}",
            'auditable_type'  => 'document_review',
            'auditable_id'    => $review->id,
            'metadata'        => ['stage_index' => $stage->stage_index, 'comment' => $comment],
        ]);

        if ($decision === ReviewStage::DECISION_REJECTED) {
            StageRejected::dispatch($review, $stage, $comment);
            $this->statusManager->transition($review, DocumentReview::STATUS_REJECTED);
            ReviewRejected::dispatch($review, $comment);
            return $review->refresh();
        }

        // Approved — check if there's a next stage
        StageApproved::dispatch($review, $stage);

        $nextIndex = $stage->stage_index + 1;
        $nextStage = $review->stages()->where('stage_index', $nextIndex)->first();

        if ($nextStage === null) {
            // All stages passed — review fully approved
            $this->statusManager->transition($review, DocumentReview::STATUS_APPROVED);
            ReviewApproved::dispatch($review);
        } else {
            $review->update(['current_stage_index' => $nextIndex]);
        }

        return $review->refresh();
    }

    public function getReview(string $documentId, string $orgId): ?DocumentReview
    {
        return DocumentReview::where('document_id', $documentId)
            ->where('organization_id', $orgId)
            ->with('stages')
            ->latest()
            ->first();
    }
}
