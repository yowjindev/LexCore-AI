'use client'

import { useState, useRef, useEffect } from 'react'
import { useParams } from 'next/navigation'
import { useQuery, useMutation } from '@tanstack/react-query'
import { ArrowLeft, Send, Bot, User, FileText, Loader2, MessageSquare, Plus } from 'lucide-react'
import Link from 'next/link'
import { getDocument } from '@/lib/api/documents'
import { listConversations, startConversation, sendMessage, getMessages } from '@/lib/api/conversations'
import { LoadingSpinner } from '@/components/shared/LoadingSpinner'
import { parseApiError } from '@/lib/errors'
import type { ConversationMessage, CitedChunk, ChatReply } from '@/types'

function CitationBadge({ chunk }: { chunk: CitedChunk }) {
  const [open, setOpen] = useState(false)
  return (
    <span className="inline-block">
      <button
        onClick={() => setOpen((v) => !v)}
        className="ml-1 inline-flex items-center gap-0.5 rounded bg-primary/10 px-1.5 py-0.5 text-xs font-medium text-primary hover:bg-primary/20 transition-colors"
      >
        <FileText size={10} />
        source {chunk.chunk_index + 1}
      </button>
      {open && (
        <span className="mt-1 block rounded-lg border border-border bg-muted p-2 text-xs text-muted-foreground leading-relaxed">
          {chunk.excerpt}
        </span>
      )}
    </span>
  )
}

function MessageBubble({ msg }: { msg: ConversationMessage }) {
  const isUser = msg.role === 'user'
  return (
    <div className={`flex gap-3 ${isUser ? 'flex-row-reverse' : 'flex-row'}`}>
      <div className={`shrink-0 flex h-7 w-7 items-center justify-center rounded-full ${isUser ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'}`}>
        {isUser ? <User size={14} /> : <Bot size={14} />}
      </div>
      <div className={`max-w-[80%] rounded-xl px-4 py-2.5 text-sm leading-relaxed ${isUser ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground'}`}>
        {msg.content}
        {msg.cited_chunks && msg.cited_chunks.length > 0 && (
          <div className="mt-2 flex flex-wrap gap-1 border-t border-border/30 pt-2">
            {msg.cited_chunks.map((c) => (
              <CitationBadge key={c.chunk_id} chunk={c} />
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

export default function ChatPage() {
  const { id } = useParams<{ id: string }>()
  const [input, setInput]                   = useState('')
  const [conversationId, setConversationId] = useState<string | null>(null)
  const [localMessages, setLocalMessages]   = useState<ConversationMessage[]>([])
  const [restored, setRestored]             = useState(false)
  const bottomRef                           = useRef<HTMLDivElement>(null)

  // Fetch document
  const { data: doc, isPending: docPending } = useQuery({
    queryKey: ['documents', id],
    queryFn:  () => getDocument(id),
  })

  // Fetch user's existing conversations for this document
  const { data: conversationsData, isPending: convsPending } = useQuery({
    queryKey: ['conversations', id],
    queryFn:  () => listConversations(id),
    enabled:  !!id,
  })

  // On first load, auto-select the most recent conversation
  useEffect(() => {
    if (restored || convsPending) return
    const convs = conversationsData?.data ?? []
    if (convs.length > 0) {
      setConversationId(convs[0].id) // sorted by updated_at desc from API
    }
    setRestored(true)
  }, [conversationsData, convsPending, restored])

  // Fetch message history when a conversation is selected and we have no local messages
  const { data: historyData, isPending: historyPending } = useQuery({
    queryKey: ['chat-messages', id, conversationId],
    queryFn:  () => getMessages(id, conversationId!),
    enabled:  !!conversationId && localMessages.length === 0,
  })

  // Populate local messages from history (only on initial load)
  useEffect(() => {
    if (historyData?.data && localMessages.length === 0) {
      setLocalMessages(historyData.data)
    }
  }, [historyData])

  // Auto-scroll to bottom on new messages
  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [localMessages])

  const send = useMutation({
    mutationFn: async ({ message, convId }: { message: string; convId: string | null }): Promise<ChatReply> => {
      if (convId) return sendMessage(id, convId, message)
      return startConversation(id, message)
    },
    onMutate: ({ message }) => {
      setLocalMessages((prev) => [...prev, {
        id:           'optimistic-' + Date.now(),
        role:         'user',
        content:      message,
        cited_chunks: null,
        created_at:   new Date().toISOString(),
      }])
    },
    onSuccess: (reply, variables) => {
      if (!conversationId) setConversationId(reply.conversation_id)
      setLocalMessages((prev) => [
        ...prev.filter((m) => !m.id.startsWith('optimistic-')),
        { id: reply.message_id + '-user', role: 'user', content: variables.message, cited_chunks: null, created_at: new Date().toISOString() },
        { id: reply.message_id, role: 'assistant', content: reply.content, cited_chunks: reply.cited_chunks, created_at: new Date().toISOString() },
      ])
    },
    onError: () => {
      setLocalMessages((prev) => prev.filter((m) => !m.id.startsWith('optimistic-')))
    },
  })

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    const msg = input.trim()
    if (!msg || send.isPending) return
    setInput('')
    send.mutate({ message: msg, convId: conversationId })
  }

  function startNewConversation() {
    setConversationId(null)
    setLocalMessages([])
    setRestored(true)
  }

  const isLoading = docPending || convsPending || (!!conversationId && historyPending && localMessages.length === 0)

  if (isLoading) {
    return (
      <div className="flex justify-center py-16">
        <LoadingSpinner />
      </div>
    )
  }

  const notReady = !doc || doc.status !== 'analyzed'

  return (
    <div className="flex flex-col h-[calc(100vh-8rem)] max-w-2xl mx-auto">
      {/* Header */}
      <div className="flex items-center gap-3 pb-4 border-b border-border shrink-0">
        <Link href={`/documents/${id}`} className="text-muted-foreground hover:text-foreground transition-colors">
          <ArrowLeft size={16} />
        </Link>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-foreground truncate">
            {doc?.title ?? doc?.original_filename ?? '…'}
          </p>
          <p className="text-xs text-muted-foreground">AI Document Chat</p>
        </div>
        {/* New conversation button — only show if there's an active conversation */}
        {conversationId && !notReady && (
          <button
            onClick={startNewConversation}
            title="Start a new conversation"
            className="flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs text-muted-foreground hover:bg-accent hover:text-foreground transition-colors"
          >
            <Plus size={12} />
            New chat
          </button>
        )}
      </div>

      {/* Not ready */}
      {notReady && (
        <div className="flex flex-1 items-center justify-center">
          <div className="text-center text-muted-foreground">
            <Bot size={32} className="mx-auto mb-3" />
            <p className="text-sm font-medium">Document not ready for chat</p>
            <p className="text-xs mt-1">The document must be fully analyzed first.</p>
            <p className="text-xs mt-0.5">Current status: <span className="font-medium">{doc?.status ?? '…'}</span></p>
          </div>
        </div>
      )}

      {/* Empty state — no prior conversations, no messages yet */}
      {!notReady && localMessages.length === 0 && (
        <div className="flex flex-1 items-center justify-center">
          <div className="text-center text-muted-foreground">
            <MessageSquare size={32} className="mx-auto mb-3" />
            <p className="text-sm font-medium">Ask anything about this document</p>
            <p className="text-xs mt-1">Answers are grounded in the document text with citations.</p>
          </div>
        </div>
      )}

      {/* Messages */}
      {!notReady && localMessages.length > 0 && (
        <div className="flex-1 overflow-y-auto py-4 space-y-4">
          {localMessages.map((msg) => (
            <MessageBubble key={msg.id} msg={msg} />
          ))}
          {send.isPending && (
            <div className="flex gap-3">
              <div className="shrink-0 flex h-7 w-7 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <Bot size={14} />
              </div>
              <div className="rounded-xl bg-muted px-4 py-2.5">
                <Loader2 size={14} className="animate-spin text-muted-foreground" />
              </div>
            </div>
          )}
          <div ref={bottomRef} />
        </div>
      )}

      {/* Input */}
      {!notReady && (
        <>
          <form onSubmit={handleSubmit} className="flex gap-2 pt-4 border-t border-border shrink-0">
            <input
              type="text"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder="Ask a question about this document…"
              disabled={send.isPending}
              className="flex-1 rounded-lg border border-input bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50"
            />
            <button
              type="submit"
              disabled={!input.trim() || send.isPending}
              className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50"
            >
              <Send size={15} />
            </button>
          </form>
          {send.isError && (
            <p className="mt-1 text-xs text-destructive">{parseApiError(send.error)}</p>
          )}
        </>
      )}
    </div>
  )
}
