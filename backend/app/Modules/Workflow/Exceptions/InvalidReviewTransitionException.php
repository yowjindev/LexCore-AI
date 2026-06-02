<?php

namespace App\Modules\Workflow\Exceptions;

use App\Exceptions\AppException;

class InvalidReviewTransitionException extends AppException
{
    public function __construct(string $current, string $attempted)
    {
        parent::__construct(
            "Cannot transition review from '{$current}' to '{$attempted}'.",
            422,
        );
    }
}
