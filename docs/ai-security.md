# Jurivex AI — AI Security Policy

**Audience:** Platform administrators, legal team leads, security reviewers
**Last updated:** 2026-06-02
**Owner:** Platform Engineering

---

## 1. Threat Model

Jurivex AI processes untrusted legal document content (OCR text, uploaded files) and sends it to large language model APIs. The primary threat is **prompt injection**: an adversarial document that contains instructions designed to manipulate the AI into ignoring its system prompt, leaking data, or producing harmful output.

Secondary threats:
- **PII exposure**: documents containing SSNs, credit-card numbers, or passport numbers sent to third-party AI APIs without detection
- **Unauthorised AI feature access**: users without appropriate roles invoking AI analysis, chat, or search

---

## 2. Defences Implemented (Phase 2H)

### 2.1 PromptSanitizer — Structural Injection Defence

All untrusted document content is wrapped in nonce-tagged XML before reaching the AI model:

```
<document id="a3f9c1">
  {neutralised document text}
</document>
```

The nonce is a random 8-hex string generated per call. The system prompt instructs the model to treat content inside `<document>` tags as **data, never as instructions**.

**`neutralize()` escapes:** `<document`, `</document`, `<system>`, `<|im_start|>`, `<|im_end|>`, `[INST]`, `[/INST]` — injected delimiters become inert data.

**`flagSuspicious()` logs (never blocks):** Known injection lead-ins ("ignore previous instructions", "you are now", DAN patterns). Log-only because legal contracts legitimately use similar phrases — false-positive blocking would break real analysis.

**What is NOT guaranteed:** Structural delimiting is defence-in-depth, not a guarantee. A novel injection pattern may not be caught by regex flagging.

### 2.2 PII Detection

After every OCR job, extracted text is scanned for:

| Type | Pattern |
|------|---------|
| US Social Security Number | `\d{3}-\d{2}-\d{4}` |
| Credit / debit card number | 16-digit groups with optional separators |
| Passport number | 1–2 uppercase letters + 6–9 digits |

If PII is found: `documents.contains_pii = true`, `PIIDetected` event dispatched, warning logged with **redacted** sample matches.

**Phase 2H scope:** Detection and flagging only. Full PII redaction before AI API calls is a future phase concern.

### 2.3 AI Feature RBAC

Three Spatie permissions gate AI features:

| Permission | Who has it | Feature |
|------------|-----------|---------|
| `documents.ai.analyze` | admin, manager, superadmin | Manual AI analysis trigger |
| `documents.ai.chat` | admin, manager, staff, superadmin | Document chat (RAG) |
| `documents.search.semantic` | admin, manager, staff, superadmin | Semantic search |

Staff can chat and search but cannot manually trigger AI analysis.

### 2.4 Complete Audit Trail

Every AI interaction writes to the append-only `audit_logs` table:

| Action | When written |
|--------|-------------|
| `chat.message.sent` | Every successful chat reply (both new and existing conversations) |
| `search.query.executed` | Every semantic search |
| `document.retried` | Manual document retry |
| `flag.resolved` | Compliance flag resolution |

Logs include `user_id`, `organization_id`, `document_id` where applicable. Append-only (no `updated_at`).

### 2.5 Raw Response Storage

Every AI API call stores the raw model response in `ai_requests.raw_response`. Also records `user_id` for chat calls. Enables post-incident review, unexpected output detection, and compliance auditing. Access restricted to superadmins via the AI usage API.

---

## 3. How to Report a Suspected Injection Incident

1. Note the document ID, the suspicious output, and the timestamp
2. Contact the platform administrator immediately — do not re-process the document
3. The platform team will review `audit_logs` and `ai_requests.raw_response` for the affected call
4. The document's extraction records will be examined

---

## 4. Known Limitations

- **PII redaction is not implemented.** Detection only — PII-flagged documents are still sent to external AI APIs in Phase 2H.
- **Injection flagging is advisory.** No automated blocking occurs on suspicious patterns.
- **Semantic search has no result-level access control.** Any org member with `documents.search.semantic` can find chunks from any document in the organisation's corpus. This is by design for the standalone search feature. Document chat (`documents.ai.chat`) is scoped to the single document being viewed — `SemanticSearchService::search()` accepts an optional `documentId` filter, used by `ChatService` but not by the standalone search endpoint.
- **Provider-specific delimiters.** The XML approach works well for Gemini and Claude. A future provider may require a different strategy in `PromptSanitizer`.
