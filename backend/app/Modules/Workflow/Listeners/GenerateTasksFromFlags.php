<?php

namespace App\Modules\Workflow\Listeners;

use App\Models\User;
use App\Modules\Compliance\Events\ComplianceFlagGenerated;
use App\Modules\Workflow\Services\TaskService;
use Illuminate\Support\Facades\Log;

class GenerateTasksFromFlags
{
    public function __construct(private readonly TaskService $taskService) {}

    public function handle(ComplianceFlagGenerated $event): void
    {
        $flag = $event->flag;

        // Only auto-create tasks for high/critical AI-generated flags
        if (! $flag->ai_generated) {
            return;
        }

        if (! in_array($flag->severity, ['high', 'critical'], true)) {
            return;
        }

        // Use a system user — the org's first admin, or fall back to any user in the org
        $creator = User::where('organization_id', $flag->organization_id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->first()
            ?? User::where('organization_id', $flag->organization_id)->first();

        if ($creator === null) {
            Log::warning('GenerateTasksFromFlags: no user found for org', ['org_id' => $flag->organization_id]);
            return;
        }

        $this->taskService->createFromFlag($flag, $creator);
    }
}
