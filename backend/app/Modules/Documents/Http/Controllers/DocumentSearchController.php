<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Exceptions\ForbiddenException;
use App\Modules\AI\Embeddings\DTOs\SearchResult;
use App\Modules\AI\Embeddings\Services\SemanticSearchService;
use App\Modules\Auth\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DocumentSearchController extends Controller
{
    public function __construct(private readonly SemanticSearchService $searchService) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermissionTo('documents.search.semantic')) {
            throw new ForbiddenException('You do not have permission to use semantic search.');
        }

        $request->validate([
            'q'     => 'required|string|min:2|max:500',
            'limit' => 'integer|min:1|max:20',
        ]);

        $query   = $request->string('q')->toString();
        $limit   = $request->integer('limit', 10);
        $results = $this->searchService->search(
            $request->user()->organization_id,
            $query,
            $limit,
        );

        AuditLog::create([
            'organization_id' => $request->user()->organization_id,
            'user_id'         => $request->user()->id,
            'action'          => 'search.query.executed',
            'auditable_type'  => 'search',
            'auditable_id'    => null,
            'metadata'        => [
                'query'        => $query,
                'result_count' => count($results),
                'limit'        => $limit,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data'    => array_map(fn (SearchResult $r) => [
                'chunk_id'          => $r->chunkId,
                'document_id'       => $r->documentId,
                'document_title'    => $r->documentTitle,
                'original_filename' => $r->originalFilename,
                'chunk_text'        => $r->chunkText,
                'score'             => round($r->score, 4),
                'chunk_index'       => $r->chunkIndex,
            ], $results),
            'message' => 'OK',
            'meta'    => ['query' => $query, 'count' => count($results)],
        ]);
    }
}
