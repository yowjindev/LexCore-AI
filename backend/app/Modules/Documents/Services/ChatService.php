<?php

namespace App\Modules\Documents\Services;

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
        $response = $observable->complete($prompt);
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
You are a legal document assistant. Answer questions using ONLY the provided document excerpts below.
- If the answer is not in the excerpts, respond: "I couldn't find that information in this document."
- When you quote or reference content from an excerpt, place the citation marker [CHUNK:{chunk_id}] immediately after.
- Be concise, precise, and professional. Do not invent facts.
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
