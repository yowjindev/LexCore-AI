<?php

namespace App\Modules\Workflow\Events;

use App\Modules\Workflow\Models\DocumentReview;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReviewRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DocumentReview $review,
        public readonly ?string        $comment = null,
    ) {}
}
