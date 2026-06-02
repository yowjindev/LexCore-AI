<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Exceptions\Documents\DocumentNotFoundException;
use App\Exceptions\ForbiddenException;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Documents\DTOs\ChatResponse;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentConversation;
use App\Modules\Documents\Services\ChatService;
use App\Modules\Documents\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ChatService     $chatService,
        private readonly DocumentService $documentService,
    ) {}

    /**
     * List all conversations the current user has for a given document.
     */
    public function index(Request $request, string $documentId): JsonResponse
    {
        $document = $this->getDocumentOrFail($documentId, $request->user());

        $conversations = DocumentConversation::where('document_id', $document->id)
            ->where('user_id', $request->user()->id)
            ->withCount('messages')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $conversations->map(fn ($c) => [
                'id'            => $c->id,
                'document_id'   => $c->document_id,
                'message_count' => $c->messages_count,
                'created_at'    => $c->created_at,
                'updated_at'    => $c->updated_at,
            ]),
            'message' => 'OK',
            'meta'    => [],
        ]);
    }

    /**
     * Start a new conversation and send the first message.
     */
    public function store(Request $request, string $documentId): JsonResponse
    {
        if (! $request->user()->hasPermissionTo('documents.ai.chat')) {
            throw new ForbiddenException('You do not have permission to use document chat.');
        }

        $request->validate(['message' => 'required|string|min:2|max:2000']);

        $document = $this->getDocumentOrFail($documentId, $request->user());
        $this->assertDocumentIsAnalyzed($document);

        $chat = $this->chatService->ask(
            $document,
            $request->user(),
            $request->string('message')->toString(),
        );

        AuditLog::create([
            'organization_id' => $request->user()->organization_id,
            'user_id'         => $request->user()->id,
            'action'          => 'chat.message.sent',
            'auditable_type'  => 'document',
            'auditable_id'    => $document->id,
            'metadata'        => [
                'conversation_id' => $chat->conversationId,
                'prompt_tokens'   => $chat->promptTokens,
                'model'           => $chat->model,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatChatResponse($chat),
            'message' => 'Message sent.',
            'meta'    => [],
        ], 201);
    }

    /**
     * Send a message in an existing conversation.
     */
    public function reply(Request $request, string $documentId, string $conversationId): JsonResponse
    {
        if (! $request->user()->hasPermissionTo('documents.ai.chat')) {
            throw new ForbiddenException('You do not have permission to use document chat.');
        }

        $request->validate(['message' => 'required|string|min:2|max:2000']);

        $document = $this->getDocumentOrFail($documentId, $request->user());
        $this->assertDocumentIsAnalyzed($document);

        $conversation = DocumentConversation::where('id', $conversationId)
            ->where('document_id', $document->id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $chat = $this->chatService->ask(
            $document,
            $request->user(),
            $request->string('message')->toString(),
            $conversation,
        );

        AuditLog::create([
            'organization_id' => $request->user()->organization_id,
            'user_id'         => $request->user()->id,
            'action'          => 'chat.message.sent',
            'auditable_type'  => 'document',
            'auditable_id'    => $document->id,
            'metadata'        => [
                'conversation_id' => $chat->conversationId,
                'prompt_tokens'   => $chat->promptTokens,
                'model'           => $chat->model,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatChatResponse($chat),
            'message' => 'Message sent.',
            'meta'    => [],
        ]);
    }

    /**
     * Get the full message history of a conversation.
     */
    public function messages(Request $request, string $documentId, string $conversationId): JsonResponse
    {
        $document = $this->getDocumentOrFail($documentId, $request->user());

        $conversation = DocumentConversation::where('id', $conversationId)
            ->where('document_id', $document->id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $messages = $conversation->messages()
            ->select(['id', 'role', 'content', 'cited_chunks', 'created_at'])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $messages,
            'message' => 'OK',
            'meta'    => [],
        ]);
    }

    private function getDocumentOrFail(string $id, $user): Document
    {
        $document = Document::where('id', $id)
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->first();

        if ($document === null) {
            throw new DocumentNotFoundException();
        }

        return $document;
    }

    private function assertDocumentIsAnalyzed(Document $document): void
    {
        if ($document->status !== Document::STATUS_ANALYZED) {
            throw new ForbiddenException(
                'Document must be fully analyzed before chatting. Current status: ' . $document->status
            );
        }
    }

    private function formatChatResponse(ChatResponse $chat): array
    {
        return [
            'conversation_id'   => $chat->conversationId,
            'message_id'        => $chat->messageId,
            'content'           => $chat->content,
            'cited_chunks'      => $chat->citedChunks,
            'prompt_tokens'     => $chat->promptTokens,
            'completion_tokens' => $chat->completionTokens,
            'model'             => $chat->model,
        ];
    }
}
