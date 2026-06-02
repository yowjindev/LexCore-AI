<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Models\Task;
use App\Modules\Workflow\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $taskService) {}

    public function index(Request $request): JsonResponse
    {
        $tasks = Task::where('organization_id', $request->user()->organization_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('assigned_to'), fn ($q) => $q->where('assigned_to', $request->input('assigned_to')))
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $tasks,
            'message' => 'OK',
            'meta'    => ['count' => $tasks->count()],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'action'      => 'required|in:complete,cancel,assign',
            'assigned_to' => 'required_if:action,assign|uuid|nullable',
        ]);

        $task = Task::where('id', $id)
            ->where('organization_id', $request->user()->organization_id)
            ->firstOrFail();

        $action = $request->string('action')->toString();

        $updated = match ($action) {
            'complete' => $this->taskService->complete($task, $request->user()),
            'cancel'   => $this->taskService->cancel($task, $request->user()),
            'assign'   => (function () use ($task, $request): Task {
                $assignee = \App\Models\User::where('id', $request->input('assigned_to'))
                    ->where('organization_id', $request->user()->organization_id)
                    ->firstOrFail();
                return $this->taskService->assign($task, $assignee, $request->user());
            })(),
        };

        return response()->json([
            'success' => true,
            'data'    => $updated,
            'message' => ucfirst($action) . 'd.',
            'meta'    => [],
        ]);
    }
}
