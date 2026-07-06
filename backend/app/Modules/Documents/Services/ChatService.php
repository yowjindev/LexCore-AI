<?php

namespace App\Modules\Documents\Services;

use App\Exceptions\AI\AIProviderException;
use App\Models\User;
use App\Modules\AI\Contracts\AIClientContract;
use App\Modules\AI\Embeddings\DTOs\SearchResult;
use App\Modules\AI\Security\PromptSanitizer;
use App\Modules\AI\Services\ObservableAIClient;
use App\Modules\AI\Embeddings\Services\SemanticSearchService;
use App\Modules\Documents\DTOs\ChatResponse;
use App\Modules\Documents\Models\ConversationMessage;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentConversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatService
{
    private const MAX_HISTORY_TURNS = 4;
    private const TOP_K_CHUNKS      = 5;

    public function __construct(
        private readonly SemanticSearchService $searchService,
        private readonly AIClientContract      $aiClient,
        private readonly PromptSanitizer       $sanitizer,
    ) {}

    public function ask(
        Document              $document,
        User                  $user,
        string                $question,
        ?DocumentConversation $conversation = null,
    ): ChatResponse {
        if ($conversation === null) {
            $conversation = DocumentConversation::create([
                'document_id'     => $document->id,
                'user_id'         => $user->id,
                'organization_id' => $document->organization_id,
            ]);
        }

        $chunks = $this->searchService->search(
            $document->organization_id,
            $question,
            self::TOP_K_CHUNKS,
            $document->id,
        );

        $history = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit(self::MAX_HISTORY_TURNS * 2)
            ->get()
            ->reverse()
            ->values();

        $prompt = $this->buildPrompt($document, $question, $chunks, $history->all());

        $observable = new ObservableAIClient(
            $this->aiClient,
            $document->organization_id,
            $document->id,
            'chat',
            $user->id,
        );
        $webSearch = (bool) config('ai.web_search_enabled');

        try {
            $response = $observable->complete($prompt, ['web_search' => $webSearch]);
        } catch (AIProviderException $e) {
            // Grounded (web-search) requests draw from a separate, much smaller
            // provider quota bucket. When that bucket is exhausted, degrade to a
            // normal excerpt-only answer instead of failing the whole chat turn.
            if (! $webSearch || ! $this->isQuotaError($e)) {
                throw $e;
            }
            Log::warning('Web-grounded chat hit provider quota — retrying without web search', [
                'document_id' => $document->id,
                'error'       => $e->getMessage(),
            ]);
            $response = $observable->complete($prompt, ['web_search' => false]);
        }
        $citedChunks = $this->parseCitations($response->content, $chunks);

        // Strip [CHUNK:uuid] markers from the displayed content — citations are
        // already captured in citedChunks and rendered as clickable badges.
        $cleanContent = trim(preg_replace('/\[CHUNK:[0-9a-f-]{36}\]/i', '', $response->content));

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => ConversationMessage::ROLE_USER,
            'content'         => $question,
        ]);

        $assistantMsg = ConversationMessage::create([
            'conversation_id'   => $conversation->id,
            'role'              => ConversationMessage::ROLE_ASSISTANT,
            'content'           => $cleanContent,
            'cited_chunks'      => $citedChunks,
            'prompt_tokens'     => $response->inputTokens,
            'completion_tokens' => $response->outputTokens,
        ]);

        return new ChatResponse(
            content:          $cleanContent,
            citedChunks:      $citedChunks,
            promptTokens:     $response->inputTokens,
            completionTokens: $response->outputTokens,
            model:            $response->model,
            conversationId:   $conversation->id,
            messageId:        $assistantMsg->id,
        );
    }

    private function isQuotaError(AIProviderException $e): bool
    {
        return str_contains($e->getMessage(), '429')
            || str_contains($e->getMessage(), 'RESOURCE_EXHAUSTED');
    }

    /**
     * @param  SearchResult[]         $chunks
     * @param  ConversationMessage[]  $history
     */
    private function buildPrompt(
        Document $document,
        string   $question,
        array    $chunks,
        array    $history,
    ): string {
        $parts = [];

        $parts[] = <<<SYSTEM
You are a legal document assistant helping a user understand the document below.
- Ground any claim about what the document says, states, or requires in the excerpts. When you do, place the citation marker [CHUNK:{chunk_id}] immediately after it. Never state a document-specific fact (a party, date, clause, obligation, number) that isn't backed by an excerpt.
- The user may also ask questions that are related to the document's subject matter but not literally answered in the excerpts (e.g. general legal concepts, definitions, or implications relevant to this type of document). Answer those using your own legal knowledge, and clearly signal that it's general guidance rather than something drawn from the document itself — e.g. "The document doesn't specify this, but generally...".
- You may search the web, but ONLY for topics related to this document's subject matter — never for unrelated questions. Present web findings as supplementary guidance, clearly attributed (e.g. "According to current sources..."), not as document content. For what the document itself says, the excerpts remain the sole source of truth. If a web result contradicts the document, present both sides and flag the discrepancy for the user to review — do not silently pick one.
- Only say "I couldn't find that information in this document" when the question is specifically about the document's content and the excerpts genuinely don't cover it — don't use it to dodge a related question you can otherwise answer.
- If the question is unrelated to the document or this type of document entirely, say so instead of answering.
- Be concise, precise, and professional. Do not invent facts about the document.
SYSTEM;

        $parts[] = "DOCUMENT: {$document->title} ({$document->original_filename})";

        if ($chunks) {
            $parts[] = 'RELEVANT EXCERPTS:';
            foreach ($chunks as $chunk) {
                $this->sanitizer->flagSuspicious($chunk->chunkText);
                $wrapped = $this->sanitizer->wrap($chunk->chunkText, 'excerpt');
                $parts[] = "[CHUNK:{$chunk->chunkId}] (chunk {$chunk->chunkIndex}):\n{$wrapped}";
            }
        } else {
            $parts[] = 'RELEVANT EXCERPTS: (none found — document may not be indexed yet)';
        }

        if (count($history) > 0) {
            $parts[] = "CONVERSATION HISTORY:";
            foreach ($history as $msg) {
                $role    = $msg->role === ConversationMessage::ROLE_USER ? 'User' : 'Assistant';
                $parts[] = "{$role}: {$msg->content}";
            }
        }

        $parts[] = 'User: ' . $this->sanitizer->wrap($question, 'user_question');
        $parts[] = "Assistant:";

        return implode("\n\n", $parts);
    }

    /**
     * @param  SearchResult[] $chunks
     * @return array<int, array{chunk_id: string, excerpt: string, chunk_index: int, score: float}>
     */
    private function parseCitations(string $content, array $chunks): array
    {
        $chunkMap = [];
        foreach ($chunks as $chunk) {
            $chunkMap[$chunk->chunkId] = $chunk;
        }

        $cited   = [];
        $seenIds = [];

        preg_match_all('/\[CHUNK:([0-9a-f-]{36})\]/i', $content, $matches);

        foreach ($matches[1] as $chunkId) {
            if (isset($seenIds[$chunkId]) || ! isset($chunkMap[$chunkId])) {
                continue;
            }
            $seenIds[$chunkId] = true;
            $chunk             = $chunkMap[$chunkId];
            $cited[]           = [
                'chunk_id'    => $chunkId,
                'excerpt'     => Str::limit($chunk->chunkText, 300),
                'chunk_index' => $chunk->chunkIndex,
                'score'       => $chunk->score,
            ];
        }

        return $cited;
    }
}
