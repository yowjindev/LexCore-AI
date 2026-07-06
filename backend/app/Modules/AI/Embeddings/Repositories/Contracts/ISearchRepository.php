<?php

namespace App\Modules\AI\Embeddings\Repositories\Contracts;

use App\Modules\AI\Embeddings\DTOs\SearchResult;

interface ISearchRepository
{
    /**
     * Find document chunks similar to the given embedding vector.
     *
     * CRITICAL: organizationId is ALWAYS required — never omit it.
     * Omitting it would expose every organization's documents to every user.
     *
     * Pass $documentId to restrict results to a single document (e.g. document
     * chat, where only chunks from the document being discussed may surface).
     *
     * @param  float[]       $queryEmbedding
     * @return SearchResult[]
     */
    public function findSimilarChunks(
        string  $organizationId,
        array   $queryEmbedding,
        int     $limit = 10,
        ?string $documentId = null,
    ): array;
}
