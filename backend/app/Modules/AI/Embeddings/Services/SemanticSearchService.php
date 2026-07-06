<?php

namespace App\Modules\AI\Embeddings\Services;

use App\Modules\AI\Contracts\EmbeddingClientContract;
use App\Modules\AI\Embeddings\DTOs\SearchResult;
use App\Modules\AI\Embeddings\Repositories\Contracts\ISearchRepository;
use Illuminate\Support\Facades\Cache;

class SemanticSearchService
{
    public function __construct(
        private readonly EmbeddingClientContract $embeddingClient,
        private readonly ISearchRepository       $searchRepository,
    ) {}

    /**
     * Search for document chunks semantically similar to the query.
     *
     * The query embedding is cached for 5 minutes to avoid repeated API calls.
     * Cache is scoped by organization_id to prevent cross-tenant data leakage.
     *
     * @return SearchResult[]
     */
    public function search(
        string  $organizationId,
        string  $query,
        int     $limit = 10,
        ?string $documentId = null,
    ): array {
        // Cache the query embedding for 5 minutes — embedding API calls are not free
        $cacheKey = 'search:' . $organizationId . ':' . md5($query);

        $embedding = Cache::remember($cacheKey, 300, fn () =>
            $this->embeddingClient->embed($query)
        );

        return $this->searchRepository->findSimilarChunks($organizationId, $embedding, $limit, $documentId);
    }
}
