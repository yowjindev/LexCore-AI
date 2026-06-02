<?php

namespace App\Modules\Workflow\Events;

use App\Modules\Workflow\Models\DocumentReview;
use App\Modules\Workflow\Models\ReviewStage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StageApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DocumentReview $review,
        public readonly ReviewStage    $stage,
    ) {}
}
