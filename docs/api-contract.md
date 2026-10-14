# API contract

Two HTTP surfaces exist:

1. **WordPress REST route** – the only endpoint browsers call.
2. **Backend API** – called only by servers (WordPress, operator CLI, monitoring).

All bodies are JSON (UTF-8). Error responses never contain stack traces, provider payloads, hostnames or secrets.

---

## 1. WordPress: `POST /wp-json/wp-knowledge-chat/v1/chat`

Public (anonymous visitors). Same-origin only when an `Origin` header is present.

**Request**

```json
{ "message": "What services does Inpartner offer?", "language": "en" }
```

| Field | Type | Rules |
|---|---|---|
| `message` | string | required; control characters removed; trimmed; 1–500 characters (multibyte-aware) |
| `language` | `"en"` \| `"id"` | optional; defaults to the *Default Language* setting |
| `history` | array | optional; at most 4 turns: `[ { "role": "user" \| "model", "text": string } ]` |

**200 response**: re-validated by WordPress before it reaches the browser.

```json
{
  "answer": "…plain text…",
  "sources": [{ "title": "Distribution Partner Sourcing advisory", "url": "https://inpartnerglobal.com/distribution-partner-sourcing-advisory/" }],
  "grounded": true
}
```

- `answer`: plain text with tags stripped, at most 2000 characters.
- `sources`: at most 5, deduplicated. Each URL must be `https` on an approved host (`wp_knowledge_chat_allowed_source_hosts`); others are dropped.
- `grounded` is forced to `false` when no valid source remains. It is a quality indicator, **not** a guarantee of correctness.

**Errors** (WordPress `WP_Error` format: `{ "code", "message", "data": { "status" } }`, message localised to `en`/`id`)

| Status | `code` | When |
|---|---|---|
| 400 | `wkc_invalid_message`, `wkc_message_too_long`, `rest_invalid_param`, `rest_missing_callback_param` | invalid input |
| 403 | `wkc_forbidden_origin` | `Origin` is not this site (including `null`) |
| 429 | `wkc_rate_limited` | more than 6 requests/min per visitor IP (sliding window; filterable) or backend limit; `Retry-After` header set (WordPress limit: 60 s; backend limit: passed through, at most one day) |
| 503 | `wkc_unavailable` | chatbot disabled, backend not configured, backend error/timeout/invalid response |

Response headers include `Cache-Control: no-store, private` and `X-Robots-Tag: noindex`.

---

## 2. Backend: `POST /api/chat`

Server-to-server only. Requires an HMAC signature from the WordPress plugin.

**Headers**

| Header | Value |
|---|---|
| `Content-Type` | `application/json` (else 415) |
| `X-WKC-Timestamp` | Unix seconds; must be within ±300 s (`SIGNATURE_MAX_SKEW_SECONDS`) |
| `X-WKC-Nonce` | 16–64 lowercase hex characters, random per request |
| `X-WKC-Client` | opaque client id `[A-Za-z0-9_-]{0,128}` (WordPress sends a salted HMAC of the visitor IP) |
| `X-WKC-Signature` | `hex(HMAC_SHA256(CHAT_SHARED_SECRET, timestamp + "." + nonce + "." + client + "." + rawBody))` |

**Body**: same as the WordPress route; `language` defaults to `en`. Unknown fields are ignored, and client-supplied `grounded`, `sources` and `role` values have no effect.

**200 response**: `{ "answer": string, "sources": [{ "title", "url" }], "grounded": boolean }`

- Sources are looked up from the knowledge base by the ids the model cites (max 3). A URL can never come from the model.
- Fallback (out of scope, no evidence, requested fact type absent from the evidence, model declined, provider safety block, invalid or unsafe model output, or a number/URL/e-mail/phone in the answer that the evidence does not contain) → localised fallback text, `sources: []`, `grounded: false`. The reason is logged (`chat_completed outcome=… reason=…`), not returned.

**Errors**: `{ "error": { "code", "message" } }`

| Status | `code` |
|---|---|
| 400 | `invalid_json`, `invalid_request`, `invalid_message`, `message_too_long`, `invalid_language`, `invalid_history` |
| 401 | `unauthorized` (missing/malformed/expired/invalid/replayed signature) |
| 404 / 405 | `not_found` / `method_not_allowed` (`Allow` header set; CORS preflight is not supported) |
| 413 | `payload_too_large` (> 8 KiB) |
| 415 | `unsupported_media_type` |
| 429 | `rate_limited`, `daily_limit_reached` (+ `Retry-After`). The daily limit counts model calls only, so fallbacks answered without Gemini never return `daily_limit_reached`. |
| 502 | `provider_unavailable` (provider error, auth failure, blocked output) |
| 503 | `knowledge_base_unavailable` |
| 504 | `provider_timeout` |
| 500 | `internal_error` |

---

## 3. Backend: `POST /api/index`

Server-to-server, `Authorization: Bearer <INDEX_API_TOKEN>`. Limited to 30 requests/min. Body ≤ 2 MiB. **Never fetches URLs**: callers push document content.

```json
{
  "documents": [{
    "id": "page-445-contact",
    "title": "Contact",
    "url": "https://inpartnerglobal.com/contact/",
    "text": "Contact Us\n…",
    "category": "contact",
    "status": "publish",
    "updated_at": "2026-06-04T03:46:49Z"
  }],
  "delete_ids": ["page-1052-cross-border-ma"],
  "replace_all": false
}
```

| Field | Rules |
|---|---|
| `id` | `^[a-z0-9][a-z0-9._:-]*$`, ≤128 chars, unique in the request |
| `title` | 1–300 chars |
| `url` | `https`, host in `ALLOWED_SOURCE_HOSTS`, no credentials, no non-443 port; fragment removed |
| `text` | 1–100,000 chars |
| `category` | `home`, `about`, `service`, `contact`, `faq`, `article` |
| `status` | must be `"publish"`; drafts, private and other statuses are rejected |
| `updated_at` | ISO-8601 |
| `replace_all` | `true` replaces the whole KB (documents not listed are deleted); refused with an empty `documents` list |

Validation is **all-or-nothing**: one invalid document → `422 invalid_document` with `details: [{ index, field, message }]`, and nothing is applied. Unchanged documents count as `unchanged` (idempotent).

**200**: `{ "upserted": 1, "unchanged": 4, "deleted": 0, "total": 5 }`
**Errors**: 400, 401 (`WWW-Authenticate: Bearer`), 413, 415, 422, 429, 500 `persist_failed` (in-memory state rolled back).

---

## 4. Backend: `GET /api/health`

Minimal status, for load balancers and uptime monitors:

- `200 { "status": "ok", "knowledge_base": "ready" }`
- `503 { "status": "degraded", "knowledge_base": "empty" }`

There are no versions, counts, environment or configuration details.

Without `X-WKC-*` headers the endpoint is unauthenticated. When `X-WKC-Signature` is present, the request is verified like `/api/chat` with an **empty body** (same timestamp window and replay cache), and a bad signature returns `401 unauthorized`. The WordPress **Test connection** button uses this, so a wrong shared secret or clock skew is reported instead of a false "ok".
