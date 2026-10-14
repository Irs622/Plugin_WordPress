# Architecture

## Components and data flow

```
 Visitor browser                         WordPress (shared hosting, PHP)                 AI backend (Node.js, external host)        Google
 ───────────────                         ───────────────────────────────                 ───────────────────────────────────        ──────
 chat-widget.js  ── POST same-origin ──▶  /wp-json/wp-knowledge-chat/v1/chat   ── HMAC ──▶  POST /api/chat                    ──▶   Gemini
 (vanilla JS)       {message,language}    • enabled? configured? origin ok?     signed       • verify signature + replay         API
                                          • validate message (≤500 chars)       HTTPS        • rate limit (client/global/daily)
                ◀── {answer,sources, ──   • per-IP sliding-window rate limit               • validate request
                     grounded}            • salted client id (no raw IP)                   • BM25 retrieval over KB chunks
                                          • re-validate response, URL allowlist ◀──────────  • no evidence → fallback (no LLM)
                                                                                             • prompt with delimited sources
                                                                                             • parse + validate JSON output
                                                                                             • sources resolved from KB only
 Admin (manage_options) ─ options.php ─▶  Settings API → option wp_knowledge_chat_settings

 Operator (CLI) ── npm run kb:build ──▶ fetches ONLY page IDs in data/sources.json (public REST API) → data/kb.json (reviewed)
                ── npm run kb:push  ──▶ POST /api/index (Bearer INDEX_API_TOKEN) → validated, persisted atomically
```

## Why this shape (simplest viable)

| Decision | Choice | Reason |
|---|---|---|
| Browser → AI path | Browser calls **its own WordPress REST route**; WordPress proxies to the backend | Shared hosting is PHP-only. The proxy keeps the backend URL and secret out of the browser, and the backend needs no CORS. It also gives a natural place for per-visitor limits. Cost: one PHP worker is held per chat request (≤20 s timeout). This is fine at MVP traffic. |
| WP ↔ backend auth | HMAC-SHA256 over `timestamp.nonce.clientId.body`, 5-min window, replay cache | Stateless, no TLS client certificates needed. Tampering with the body or client id breaks the signature. |
| Secret storage (WP) | `wp-config.php` constants, not options | Never in the database, the admin UI, exports or frontend. Settings stay limited to the four PRD fields. |
| Backend framework | `node:http`, **zero runtime dependencies** | Small attack surface, no supply-chain churn, and full control over body-size limits. Dev-only dependencies: TypeScript, `@types/node`. |
| LLM integration | Gemini REST `generateContent` via `fetch`, JSON response schema | No SDK needed. Overall deadline, one bounded retry, and errors never echo provider payloads. |
| Retrieval | BM25 lexical search, in memory, plus a small Indonesian→English term map | The corpus is 5 pages (10 chunks). The offline evaluation hits the expected document for 100% of answerable questions. Embeddings and pgvector are not justified (PRD §8). |
| Knowledge-base storage | JSON file (`data/kb.json`), atomic writes | No database to run. The file is diffable and reviewable in git. |
| Grounding enforcement | Model returns `source_ids` only; backend maps ids → KB title/URL | The model cannot introduce URLs. `grounded:true` without a valid id becomes the fallback. |
| Unanswerable questions | Retrieval threshold → deterministic fallback **without calling the LLM** | Cheaper, and immune to prompt injection when nothing relevant is retrieved. |
| Rate limiting | WP: per-IP sliding window (transients). Backend: per-client + global fixed window + daily budget (in memory) | Protects PHP workers and provider cost independently. In-memory limits assume a single backend instance (see deployment). |
| Widget | Vanilla JS + CSS scoped under `#wkc-root`, deferred, footer | No framework needed. ID-scoped CSS beats Hello Elementor/Elementor kit button styles without `!important`; verified in Chrome. |
| Conversation memory | `sessionStorage` in the browser only; backend is stateless per question | No personal data stored server-side. Follow-up questions are answered independently (documented limitation). |
| Content sync | CLI build from a reviewed allowlist + authenticated push | PRD lists automatic sync as P1. `/api/index` accepts documents but never fetches URLs, so it cannot become an SSRF/open proxy. |

## Module map

```
plugin/wp-knowledge-chat/
  wp-knowledge-chat.php          bootstrap, constants, lifecycle hooks
  includes/class-plugin.php      hook wiring, widget loading rules, frontend config (no secrets)
  includes/class-settings.php    defaults, Settings API registration, validation, URL policy, migration
  includes/class-admin-page.php  WP Knowledge Chat → Settings screen, nonce-protected "Test connection"
  includes/class-rest-controller.php  public chat route, origin check, rate limit, response re-validation
  includes/class-backend-client.php   signed server-to-server client (wp_remote_post), health check
  assets/js/chat-widget.js       widget (DOM via textContent only, URL allowlist, a11y, sessionStorage)
  assets/css/chat-widget.css     #wkc-root-scoped styles, responsive bottom sheet on mobile
  uninstall.php                  removes the option and plugin transients

backend/
  src/server.ts                  process entry: config, KB load, HTTP server timeouts, graceful shutdown
  src/app.ts                     router + dependency wiring (testable without a socket)
  src/config/env.ts              validated environment configuration (fails fast)
  src/routes/{chat,index,health}.ts
  src/services/retrieval.ts      BM25
  src/services/text.ts           normalisation, stemming, ID→EN query expansion
  src/services/knowledge-base.ts document validation, chunking, store, atomic persistence
  src/services/llm.ts            Gemini client (timeout, retry, error mapping)
  src/services/chat.ts           grounding orchestration, prompt, output validation
  src/middleware/rate-limit.ts   fixed window + daily budget
  src/lib/{auth,http,logger,html-to-text}.ts
  scripts/build-kb.ts            builds data/kb.json from data/sources.json
  scripts/push-kb.ts             pushes a reviewed KB to /api/index
  scripts/mock-gemini.ts         local Gemini stand-in for tests and offline E2E
  eval/                          65-question dataset + retrieval/live evaluation runner
```

`embeddings.ts` and a plugin-side `class-content-sync.php` from the suggested tree were **intentionally not created**: neither is needed for the MVP, and the PRD asks for no empty modules.

## Deviations from the suggested structure / PRD

1. **Backend URL and secret are `wp-config.php` constants, not settings.** The four PRD settings are unchanged. Secrets must not be stored in options.
2. **No WordPress nonce on the public chat endpoint.** For anonymous visitors a nonce authorises nothing, and it breaks when LiteSpeed serves cached pages older than the nonce lifetime. Origin checks, rate limits and backend signing are used instead. Admin actions do use nonces.
3. **Single-turn answers.** The widget shows session history, but each question is answered independently. This keeps the backend stateless and limits the prompt-injection surface.
4. **Content sync is a CLI workflow, not a plugin feature** (P1 in the PRD).
