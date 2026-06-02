<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Exceptions\Documents\DocumentNotFoundException;
use App\Exceptions\ForbiddenException;
use App\Modules\Documents\Models\Document;
use App\Modules\Workflow\Models\DocumentReview;
use App\Modules\Workflow\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ReviewController extends Controller
{
    public function __construct(private readonly WorkflowService $workflowService) {}

    public function show(Request $request, string $documentId): JsonResponse
    {
        $document = $this->getDocumentOrFail($documentId, $request->user()->organization_id);
        $review   = $this->workflowService->getReview($document->id, $request->user()->organization_id);

        return response()->json([
            'success' => true,
            'data'    => $review,
            'message' => 'OK',
            'meta'    => [],
        ]);
    }

    public function store(Request $request, string $documentId): JsonResponse
    {
        $request->validate(['template_id' => 'nullable|uuid']);

        $document = $this->getDocumentOrFail($documentId, $request->user()->organization_id);

        if (! $request->user()->hasAnyRole(['admin', 'manager', 'superadmin'])) {
            throw new ForbiddenException('Only admins and managers can start reviews.');
        }

        $review = $this->workflowService->startReview(
            $document,
            $request->user(),
            $request->filled('template_id') ? $request->string('template_id')->toString() : null,
        );

        return response()->json([
            'success' => true,
            'data'    => $review,
            'message' => 'Review started.',
            'meta'    => [],
        ], 201);
    }

    public function advance(Request $request, string $reviewId): JsonResponse
    {
        $request->validate([
            'decision' => 'required|in:approved,rejected',
            'comment'  => 'nullable|string|max:1000',
        ]);

        $review = DocumentReview::where('id', $reviewId)
            ->where('organization_id', $request->user()->organization_id)
            ->firstOrFail();

        $updatedReview = $this->workflowService->advanceStage(
            $review,
            $request->user(),
            $request->string('decision')->toString(),
            $request->filled('comment') ? $request->string('comment')->toString() : null,
        );

        return response()->json([
            'success' => true,
            'data'    => $updatedReview->load('stages'),
            'message' => 'Stage advanced.',
            'meta'    => [],
        ]);
    }

    private function getDocumentOrFail(string $id, string $orgId): Document
    {
        $doc = Document::where('id', $id)
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->first();

        if ($doc === null) {
            throw new DocumentNotFoundException();
        }

        return $doc;
    }
}
