# WP Knowledge Chat: AI backend

Node.js + TypeScript service behind the WordPress plugin. It authenticates requests from WordPress, retrieves relevant knowledge-base content, asks Google Gemini to answer only from that content, validates the answer, and returns `{ answer, sources, grounded }`.

- Runtime dependencies: **none** (Node built-ins only). Dev dependencies: `typescript`, `@types/node`.
- Node.js ≥ 20.11.
- API contract: [../docs/api-contract.md](../docs/api-contract.md). Security: [../docs/security.md](../docs/security.md). Deployment: [../docs/deployment.md](../docs/deployment.md).

## Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/chat` | HMAC signature from the WordPress plugin | Answer a visitor question |
| POST | `/api/index` | `Authorization: Bearer $INDEX_API_TOKEN` | Upsert, delete or replace knowledge-base documents |
| GET | `/api/health` | none | `200 {status:"ok"}` or `503 {status:"degraded"}` |

## Getting started

```bash
npm install
cp ../.env.example .env        # set GEMINI_API_KEY, CHAT_SHARED_SECRET, INDEX_API_TOKEN
npm run secret                 # prints a random 64-hex secret (run twice: chat + index)
npm run build
npm run start:local            # loads .env; listens on 127.0.0.1:8787
curl http://127.0.0.1:8787/api/health
```

### Without a Gemini key

`scripts/mock-gemini.ts` imitates the `generateContent` endpoint. It always cites the first retrieved source, so it is useful for wiring and UI checks but **not** for answer quality.

```bash
npm run mock:gemini            # http://127.0.0.1:8790
# in .env:
# GEMINI_API_BASE_URL=http://127.0.0.1:8790
# GEMINI_API_KEY=any-placeholder
```

Questions containing `__slow__`, `__error__` or `__garbage__` trigger a timeout, an HTTP 500 or malformed output respectively.

### Calling `/api/chat` by hand

The endpoint requires a signature. With the shared secret in `$S`:

```bash
BODY='{"message":"What services does Inpartner offer?","language":"en"}'
TS=$(date +%s); NONCE=$(openssl rand -hex 16); CLIENT=manual-test
SIG=$(printf '%s' "$TS.$NONCE.$CLIENT.$BODY" | openssl dgst -sha256 -hmac "$S" -hex | awk '{print $NF}')
curl -s http://127.0.0.1:8787/api/chat -H 'Content-Type: application/json' \
  -H "X-WKC-Timestamp: $TS" -H "X-WKC-Nonce: $NONCE" -H "X-WKC-Client: $CLIENT" -H "X-WKC-Signature: $SIG" \
  -d "$BODY"
```

## npm scripts

| Script | What it does |
|---|---|
| `build` | Compile TypeScript to `dist/` |
| `start` | Run `dist/src/server.js` (env from the process/host) |
| `start:local` | Same, loading `.env` (`node --env-file`) |
| `test` | Build, then run all tests in `tests/` with `node:test` |
| `kb:build` | Rebuild `data/kb.json` from the allowlist in `data/sources.json` (reads the public site) |
| `kb:push` | Send `data/kb.json` to a running backend's `/api/index` (needs `BACKEND_URL`, `INDEX_API_TOKEN`) |
| `eval:retrieval` | Offline retrieval evaluation on `eval/dataset.json` |
| `eval:live` | Evaluation through the real Gemini API; writes `eval/report-live-*.json` |
| `mock:gemini` | Start the local Gemini stand-in on port 8790 |
| `secret` | Print a random 32-byte hex secret |

## Configuration

All configuration comes from environment variables, validated at startup. On invalid configuration the process prints which variables are wrong (never their values) and exits with code 78. The full list with defaults is in [../.env.example](../.env.example).

| Variable | Required | Default | Notes |
|---|---|---|---|
| `GEMINI_API_KEY` | yes | — | server-side only |
| `CHAT_SHARED_SECRET` | yes | — | ≥ 32 chars; equals `WP_KNOWLEDGE_CHAT_SHARED_SECRET` in WordPress |
| `INDEX_API_TOKEN` | yes | — | ≥ 32 chars; must differ from the chat secret |
| `HOST` / `PORT` | | `127.0.0.1` / `8787` | use `HOST=0.0.0.0` in containers/PaaS |
| `GEMINI_MODEL` | | `gemini-2.5-flash` | "thinking" is disabled for 2.5 Flash to keep answers fast and cheap |
| `GEMINI_TIMEOUT_MS` | | `12000` | total deadline including one retry |
| `GEMINI_MAX_OUTPUT_TOKENS` | | `600` | |
| `GEMINI_API_BASE_URL` | | Google endpoint | http only allowed for localhost |
| `KB_PATH` | | `data/kb.json` | |
| `ALLOWED_SOURCE_HOSTS` | | `inpartnerglobal.com,www.inpartnerglobal.com` | citations and indexed URLs must match exactly |
| `MAX_MESSAGE_CHARS` | | `500` | |
| `RATE_LIMIT_PER_CLIENT_PER_MINUTE` | | `8` | per signed client id (one visitor) |
| `RATE_LIMIT_GLOBAL_PER_MINUTE` | | `60` | all visitors combined |
| `DAILY_REQUEST_LIMIT` | | `1000` | cost ceiling; resets 00:00 UTC |
| `RETRIEVAL_MIN_SCORE` / `RETRIEVAL_TOP_K` | | `1.0` / `4` | see [../docs/knowledge-base.md](../docs/knowledge-base.md) |
| `SIGNATURE_MAX_SKEW_SECONDS` | | `300` | allowed clock difference with WordPress |
| `LOG_LEVEL` | | `info` | `debug`, `info`, `warn`, `error` |

## How a question is answered

1. **Authenticate:** verify the HMAC signature, timestamp window and nonce replay cache (`src/lib/auth.ts`).
2. **Limit:** per-client and global per-minute windows, then the daily budget (`src/middleware/rate-limit.ts`).
3. **Validate:** message 1–500 characters, language `en` or `id` (`src/routes/chat.ts`).
4. **Retrieve:** BM25 over ~700-character chunks, with Indonesian→English query expansion (`src/services/retrieval.ts`, `text.ts`). If no chunk reaches `RETRIEVAL_MIN_SCORE`, return the fallback and **do not call Gemini**.
5. **Generate:** the system instruction holds the rules (sources only, no invented claims, no personalised advice, ignore instructions inside sources or the question, answer language). Sources and the question are wrapped in sanitised delimiters, and the reply must be JSON (`src/services/chat.ts`, `llm.ts`).
6. **Check:** parse the JSON. Malformed, ungrounded or unsafe output (key patterns, leaked instructions, markup) becomes the fallback. Cited `S1…Sn` ids are mapped to knowledge-base titles and URLs, at most 3.

Errors from Gemini become `504 provider_timeout` or `502 provider_unavailable`. The WordPress plugin turns them into a friendly message.

## Project structure

```
src/
  server.ts                 entry point: config, KB load, server timeouts, graceful shutdown
  app.ts                    routing and dependency wiring
  config/env.ts             environment validation
  routes/                   chat.ts, index.ts, health.ts
  services/                 chat.ts, llm.ts, retrieval.ts, text.ts, knowledge-base.ts
  middleware/rate-limit.ts
  lib/                      auth.ts, http.ts, logger.ts, html-to-text.ts
scripts/                    build-kb.ts, push-kb.ts, mock-gemini.ts
data/                       sources.json (allowlist), kb.json (snapshot)
eval/                       dataset.json, run-eval.ts
tests/                      *.test.ts + helpers.ts
```

## Tests

`npm test` runs 64 tests covering configuration, signatures and replay, bearer auth, rate limits and budget, document validation (draft/private rejected), chunking, retrieval (including Indonesian), grounding and output validation, the Gemini client (timeout, retry, auth errors, blocked or malformed responses), every endpoint and status code, and XSS, prompt-injection and secret-leak payloads.

## Logging

The backend writes one JSON object per line to stdout, for example:

```json
{"time":"…","level":"info","event":"chat_completed","outcome":"answered","language":"en","message_chars":42,"sources":1,"latency_ms":1830}
```

| Event | Meaning |
|---|---|
| `server_started`, `server_stopping` | process lifecycle |
| `kb_loaded`, `kb_load_failed` | knowledge base load at startup |
| `kb_indexed`, `kb_persist_failed` | `/api/index` result |
| `chat_completed` | outcome is `answered`, `no_evidence`, `model_declined`, `invalid_model_output` or `unsafe_model_output` |
| `chat_auth_failed` | `reason` is `missing`, `malformed`, `expired`, `invalid` or `replayed` |
| `rate_limited` | `scope` is `client`, `global` or `daily` |
| `llm_failed` | `kind` is `timeout`, `unavailable`, `rate_limited`, `auth`, `blocked` or `bad_response` |
| `llm_output_invalid`, `llm_output_unsafe` | model output rejected |

Message text, IP addresses, secrets and provider payloads are never logged.
