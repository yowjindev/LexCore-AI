<?php

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Exceptions\InvalidReviewTransitionException;
use App\Modules\Workflow\Models\DocumentReview;

class ReviewStatusManager
{
    private const VALID_TRANSITIONS = [
        DocumentReview::STATUS_IN_REVIEW => [DocumentReview::STATUS_APPROVED, DocumentReview::STATUS_REJECTED, DocumentReview::STATUS_ARCHIVED],
        DocumentReview::STATUS_APPROVED  => [DocumentReview::STATUS_ARCHIVED],
        DocumentReview::STATUS_REJECTED  => [DocumentReview::STATUS_IN_REVIEW, DocumentReview::STATUS_ARCHIVED],
        DocumentReview::STATUS_ARCHIVED  => [],
    ];

    public function transition(DocumentReview $review, string $newStatus): void
    {
        $currentStatus = $review->status;
        $allowed       = self::VALID_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw new InvalidReviewTransitionException($currentStatus, $newStatus);
        }

        $review->update(['status' => $newStatus]);
    }

    public function canTransition(DocumentReview $review, string $newStatus): bool
    {
        $allowed = self::VALID_TRANSITIONS[$review->status] ?? [];

        return in_array($newStatus, $allowed, true);
    }
}
