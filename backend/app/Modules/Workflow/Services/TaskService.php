<?php

namespace App\Modules\Workflow\Services;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Compliance\Models\ComplianceFlag;
use App\Modules\Workflow\Events\TaskAssigned;
use App\Modules\Workflow\Events\TaskCompleted;
use App\Modules\Workflow\Models\Task;

class TaskService
{
    public function createFromFlag(ComplianceFlag $flag, User $creator): Task
    {
        $task = Task::create([
            'organization_id' => $flag->organization_id,
            'assignable_type' => 'compliance_flag',
            'assignable_id'   => $flag->id,
            'created_by'      => $creator->id,
            'title'           => 'Review: ' . $flag->title,
            'description'     => $flag->description,
            'status'          => Task::STATUS_OPEN,
            'priority'        => $this->mapSeverityToPriority($flag->severity),
        ]);

        AuditLog::create([
            'organization_id' => $flag->organization_id,
            'user_id'         => $creator->id,
            'action'          => 'task.created',
            'auditable_type'  => 'task',
            'auditable_id'    => $task->id,
            'metadata'        => ['source' => 'compliance_flag', 'flag_id' => $flag->id],
        ]);

        return $task;
    }

    public function assign(Task $task, User $assignee, User $actor): Task
    {
        $task->update([
            'assigned_to' => $assignee->id,
            'status'      => Task::STATUS_IN_PROGRESS,
        ]);

        AuditLog::create([
            'organization_id' => $task->organization_id,
            'user_id'         => $actor->id,
            'action'          => 'task.assigned',
            'auditable_type'  => 'task',
            'auditable_id'    => $task->id,
            'metadata'        => ['assigned_to' => $assignee->id],
        ]);

        TaskAssigned::dispatch($task, $assignee);

        return $task->fresh();
    }

    public function complete(Task $task, User $actor): Task
    {
        $task->update([
            'status'       => Task::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        AuditLog::create([
            'organization_id' => $task->organization_id,
            'user_id'         => $actor->id,
            'action'          => 'task.completed',
            'auditable_type'  => 'task',
            'auditable_id'    => $task->id,
            'metadata'        => [],
        ]);

        TaskCompleted::dispatch($task);

        return $task->fresh();
    }

    public function cancel(Task $task, User $actor): Task
    {
        $task->update(['status' => Task::STATUS_CANCELLED]);

        AuditLog::create([
            'organization_id' => $task->organization_id,
            'user_id'         => $actor->id,
            'action'          => 'task.cancelled',
            'auditable_type'  => 'task',
            'auditable_id'    => $task->id,
            'metadata'        => [],
        ]);

        return $task->fresh();
    }

    private function mapSeverityToPriority(string $severity): string
    {
        return match ($severity) {
            'critical' => Task::PRIORITY_URGENT,
            'high'     => Task::PRIORITY_HIGH,
            'medium'   => Task::PRIORITY_MEDIUM,
            default    => Task::PRIORITY_LOW,
        };
    }
}
