<?php

namespace App\Modules\Workflow\Providers;

use App\Modules\Compliance\Events\ComplianceFlagGenerated;
use App\Modules\Workflow\Listeners\GenerateTasksFromFlags;
use App\Modules\Workflow\Services\ReviewStatusManager;
use App\Modules\Workflow\Services\TaskService;
use App\Modules\Workflow\Services\WorkflowService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReviewStatusManager::class);
        $this->app->singleton(TaskService::class);
        $this->app->singleton(WorkflowService::class);
    }

    public function boot(): void
    {
        Event::listen(
            ComplianceFlagGenerated::class,
            [GenerateTasksFromFlags::class, 'handle'],
        );
    }
}
