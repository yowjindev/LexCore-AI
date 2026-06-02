<?php

namespace App\Modules\Workflow\Events;

use App\Modules\Workflow\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Task $task) {}
}
