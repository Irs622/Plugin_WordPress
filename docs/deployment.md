# Deployment

> Nothing in this repository has been deployed. Production (inpartnerglobal.com) was not modified; it was only read through public GET requests to build the knowledge base. Every step below needs explicit authorisation from the site owner, and **staging comes first**. Production changes, releases and secret rotation need a separate, explicit approval.

## Status legend

| Mark | Meaning |
|---|---|
| **L** | Verified locally (disposable WordPress 7.1.3 on SQLite, PHP 8.5.5, Node 25, mock Gemini, headless Chrome). Evidence: [test-report.md](test-report.md) |
| **S** | Must be verified on staging. Not done: no staging environment was available |
| **P** | Must be verified on production after approval. Not done |
| **B** | Blocked: needs credentials, infrastructure or an owner decision |

## Prerequisites

| Item | Needed for | Status |
|---|---|---|
| Gemini API key (Google AI Studio / Cloud) with billing alerts and quota limits | backend | **B**: not provided |
| A host that runs Node.js ≥ 20.11 with HTTPS (for example Cloud Run with max instances = 1, Render, Railway, Fly.io, or a small VPS behind Caddy/Nginx) | backend | **B**: not chosen |
| Staging copy of the WordPress site (same theme, plugins, LiteSpeed Cache, MySQL 8.0) | plugin acceptance | **B**: not available |
| Write access to `wp-config.php` on staging/production | plugin config | owner action |
| Answers to the open content questions (phone number, contact page, language scope, monthly budget) | config, KB | **B**: see [test-report.md](test-report.md#open-questions-for-inpartner-prd-17) |

The WordPress host only needs outbound HTTPS from PHP to the backend (`wp_remote_post`). No Node.js, SSH, cron workers or Docker are needed there.

## Scaling decision

The backend keeps rate-limit counters, the daily model-call budget and the signature replay cache **in process memory**. That is correct only for **one instance**:

- with two instances, each enforces its own limits (effective limits double) and a captured request could be replayed once against the other instance inside the 5-minute window;
- a restart resets the counters and the daily budget.

**Serverless (Netlify Functions) is not equivalent.** Every function instance has its own memory and `/tmp`, so the limits above are per instance. Since 0.2.1, `/api/index` returns **501** there, because an update would reach only one instance and vanish on cold start. Update the knowledge base by committing `data/kb.json` and redeploying. Use Netlify only after accepting per-instance limits, or after adding shared state.

Expected MVP traffic (well under the 60 requests/minute global limit) fits one small instance, so **single-instance mode is the supported deployment** and no Redis or database was added. Configure the platform accordingly (for example Cloud Run `--max-instances=1`, one Render/Railway instance, one systemd service). Move these three stores to a shared store (Redis with `INCR`/`EXPIRE` and `SET NX EX`) only if traffic or availability requirements make a second instance necessary; that is an infrastructure decision for the system administrator.

## 1. Deploy the backend over HTTPS (staging)

```bash
cd backend
npm ci
npm run build
npm test                          # on the build machine
npm run kb:validate               # the shipped knowledge base must pass
npm prune --omit=dev              # runtime has zero dependencies
# Provide env vars from the host's secret manager (see ../.env.example):
#   GEMINI_API_KEY, CHAT_SHARED_SECRET, INDEX_API_TOKEN, HOST=0.0.0.0 (PaaS), PORT, DAILY_REQUEST_LIMIT
npm start                         # node dist/src/server.js
```

- Generate secrets with `npm run secret` (64 hex characters). `CHAT_SHARED_SECRET` and `INDEX_API_TOKEN` must differ. Never put them in the repository, the WordPress database or a chat message.
- Invalid configuration stops the process with exit code 78 and names the variables (never their values).
- Ship `data/kb.json` with the build, or populate it after boot with `npm run kb:push`. Without it, `/api/health` and chat return 503 (the website is unaffected; the widget shows an error).
- Terminate TLS in front of the service. WordPress accepts plain http only for `localhost`.
- `INDEX_API_TOKEN` is only needed where `kb:push` runs; do not copy it to the WordPress server.
- For `/api/index` updates to survive redeploys, mount `data/` on a persistent volume, or treat `kb.json` in git as the source of truth and redeploy.
- Health check for the platform: `GET /api/health` (200 = ready). Logs are JSON lines on stdout and contain no message text, IPs or secrets.
- Set `DAILY_REQUEST_LIMIT` from the approved monthly budget: it counts **Gemini calls** per UTC day (fallbacks without a model call are free). Example: budget ÷ 30 ÷ cost per call.

### Process manager example (VPS)

```ini
# /etc/systemd/system/wkc-backend.service
[Service]
WorkingDirectory=/opt/wkc/backend
EnvironmentFile=/etc/wkc/backend.env      # chmod 600, owned by root
ExecStart=/usr/bin/node dist/src/server.js
User=wkc
Restart=on-failure
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/opt/wkc/backend/data
```

## 2. Build and verify the plugin ZIP

```bash
scripts/package-plugin.sh                              # dist/wp-knowledge-chat-<version>.zip + .sha256
plugin/wp-knowledge-chat/tests/bin/package-check.sh    # checksum, no dev files, install/activate/deactivate/uninstall/reinstall
plugin/wp-knowledge-chat/tests/bin/multisite-check.sh  # network install; uninstall cleans every site (only relevant for multisite)
```

Before uploading anywhere, re-check the checksum on the machine that uploads it:

```bash
cd dist && shasum -a 256 -c wp-knowledge-chat-0.2.0.zip.sha256
```

## 3. Install on WordPress staging

1. **Back up** the staging database and `wp-content/plugins`.
2. Add to `wp-config.php` *above* "That's all, stop editing":
   ```php
   define( 'WP_KNOWLEDGE_CHAT_BACKEND_URL', 'https://chat-api.example.com' );   // https, no query string
   define( 'WP_KNOWLEDGE_CHAT_SHARED_SECRET', '…same as CHAT_SHARED_SECRET…' );
   // Only behind Cloudflare/QUIC.cloud/a load balancer, with that provider's published ranges:
   // define( 'WP_KNOWLEDGE_CHAT_TRUSTED_PROXIES', '…' );
   ```
3. Plugins → Add New → Upload Plugin → choose the ZIP → Activate.
4. WP Knowledge Chat → Settings:
   - Keep **Contact URL** at the default `https://inpartnerglobal.com` unless the site owner approves a dedicated contact page (for example `/contact/`).
   - Choose the language, then **Test connection**. Expected: "backend is reachable, the shared secret is accepted and the knowledge base is ready".
5. Optional page targeting in a small mu-plugin:
   ```php
   add_filter( 'wp_knowledge_chat_should_load', fn( $load ) => $load && ( is_front_page() || is_page( array( 'about-us', 'contact' ) ) ) );
   ```
6. Optional monitoring of backend failures (no message text or IP is passed):
   ```php
   add_action( 'wp_knowledge_chat_backend_failure', function ( $reason, $status ) {
       error_log( "wp-knowledge-chat backend failure: {$reason} ({$status})" );
   }, 10, 2 );
   ```

### LiteSpeed Cache notes

- The widget reads no per-user data, so cached pages are safe to serve. The chat REST route is POST and sends `Cache-Control: no-store`.
- Saving changed settings calls `litespeed_purge_all`, because the greeting, language, contact URL and the enabled flag are embedded in cached pages. With another page cache, purge manually.
- If JS/CSS combine or minify is enabled, test the widget after purging; exclude `chat-widget.js` from "JS Combine" if it misbehaves. It already loads `defer` in the footer.
- With QUIC.cloud CDN or Cloudflare in front, set `WP_KNOWLEDGE_CHAT_TRUSTED_PROXIES`, otherwise all visitors share one rate-limit bucket (6 questions per minute for the whole site).

## 4. Staging acceptance checklist

| # | Check | L | S | P |
|---|---|---|---|---|
| 1 | Backend reachable over HTTPS only; plain http refused by WordPress | L (http only for localhost) | ☐ | ☐ |
| 2 | Secrets only in the host's secret store and `wp-config.php`; none in page source, options, logs or responses | L (E2E greps HTML, settings page, logs) | ☐ | ☐ |
| 3 | `npm run kb:validate` passes; `GET /api/health` → 200 | L | ☐ | ☐ |
| 4 | ZIP checksum verified; install/activate/deactivate/uninstall/reinstall clean | L (`package-check.sh`) | ☐ | ☐ |
| 5 | Plugin activates without PHP notices (`WP_DEBUG_LOG` on) | L (PHP 8.5.5) | ☐ (PHP 8.5.11) | ☐ |
| 6 | Settings save on **MySQL 8.0**; invalid values rejected; non-admins blocked | L (SQLite only) | ☐ | ☐ |
| 7 | **Test connection** passes; with a deliberately wrong secret it reports "rejected the shared secret" | L | ☐ | ☐ |
| 8 | Widget opens/closes on desktop and real mobile devices; header menu, Elementor sections and popups still work | L (Chrome emulation) | ☐ | ☐ |
| 9 | Keyboard and screen reader (VoiceOver/NVDA) walkthrough | L (keyboard, axe-core only) | ☐ | ☐ |
| 10 | LiteSpeed: settings change visible after save (auto purge); widget works with JS combine/minify; Apache/LiteSpeed rewrite of `/wp-json/` works | ☐ not testable locally | ☐ | ☐ |
| 11 | Behind CDN: two visitors are rate-limited separately (trusted proxies configured) | L (PHPUnit) | ☐ | ☐ |
| 12 | **Real Gemini**: `npm run eval:live` on both datasets against the staging backend; failure rate, p95 latency recorded | **B** (no key) | ☐ | — |
| 13 | Invalid Gemini key → `llm_failed kind=auth` in logs, friendly error in the widget; quota exhaustion → `rate_limited` | L (mocked) | ☐ | — |
| 14 | **Owner review** of every live answer (correct, unsupported claim, wrong refusal, tone) and of contact details | **B** | ☐ | — |
| 15 | Stopping the backend shows a friendly error; the rest of the site keeps working | L | ☐ | ☐ |
| 16 | Monitoring: backend log alerts on `llm_failed`, `chat_auth_failed`, `rate_limited scope=daily`; uptime check on `/api/health`; Google Cloud budget alert | ☐ | ☐ | ☐ |
| 17 | `DAILY_REQUEST_LIMIT` set from the approved budget | **B** (budget not decided) | ☐ | ☐ |
| 18 | Rollback rehearsed (disable, deactivate, restore previous `kb.json`) | L (disable/enable in E2E) | ☐ | ☐ |
| 19 | `npm run kb:verify-sources` passes against the site the backend cites (every KB document is on its page) | **B** (`--strict` fails until the site publishes the profile text, 2026-10-16) | ☐ | ☐ |
| 20 | Upgrade 0.2.0 → 0.2.1 keeps every lead; a widget lead reaches a staging inbox; a mail failure shows "Failed" on the dashboard | L (migration in `package-check.sh`, mail mocked) | ☐ | ☐ |
| 21 | Lead abuse: 4th submission in 10 min → 429; honeypot stores nothing | L (PHPUnit) | ☐ | ☐ |
| 22 | Sync: synced page edit/rename/unpublish/protect/trash each reflected in answers; backend stopped → pending notice → **Retry now** clears it | L (PHPUnit, mocked backend) | ☐ | ☐ |
| 23 | After upgrade, sync approved pages once (only synced pages are updated automatically) | — | ☐ | ☐ |
| 24 | **Written approval from the site owner before any production change** | — | — | ☐ |

## 5. Production (only after approval)

Repeat sections 1–3 against production with production secrets (new values, not the staging ones), then re-run checklist rows 1–11, 15–18 on production. Start with the widget restricted to a few pages (`wp_knowledge_chat_should_load`) for the controlled pilot (PRD §15 phase 5).

## 6. Rollback

| Situation | Action | Effect |
|---|---|---|
| Widget misbehaving | Settings → untick **Enable Chatbot** → Save (LiteSpeed purges automatically) | Widget no longer printed; chat route returns 503 |
| Plugin problem | Plugins → Deactivate (or rename `wp-content/plugins/wp-knowledge-chat` via FTP) | Plugin code not loaded; settings kept |
| Remove completely | Export leads (Analytics & Leads → Export CSV), then Deactivate → Delete | `uninstall.php` deletes settings, metrics, **all leads**, sync state and transients |
| Downgrade to 0.2.0 | Export leads first | 0.2.0 does not read the 0.2.1 lead storage (leads stay in the database but are not shown) |
| Backend problem | Stop or roll back the backend deployment | Widget shows "unavailable" errors; site unaffected |
| Bad knowledge base | Redeploy the previous `data/kb.json` or `npm run kb:push -- --file=<previous>` | Index replaced atomically |
| Cost spike | Lower `DAILY_REQUEST_LIMIT` and restart, or disable the widget | Further model calls refused with 429 |
| Leaked secret | Rotate `CHAT_SHARED_SECRET` (backend env + `wp-config.php` together) and/or `INDEX_API_TOKEN`; rotate the Gemini key in Google Cloud (needs authorisation) | Old signatures/tokens rejected after restart |

## 7. Troubleshooting

| Symptom | Likely cause | Check |
|---|---|---|
| Widget not visible | Disabled, backend constants missing/invalid (non-https URL, query string, secret < 32 chars), page-targeting filter, Elementor preview, stale page cache | Settings page "AI backend" box; view-source for `chat-widget.js` |
| Test connection: "rejected the shared secret" | `WP_KNOWLEDGE_CHAT_SHARED_SECRET` ≠ `CHAT_SHARED_SECRET`, or clock skew > 5 min | backend log `health_auth_failed reason=invalid|expired` |
| "Assistant unavailable" on every question | Backend down, wrong URL, secret mismatch (`chat_auth_failed`), outbound HTTPS blocked by host | *Test connection*; backend logs; `wp_knowledge_chat_backend_failure` action |
| Health 503 | `data/kb.json` missing or invalid | backend log `kb_load_failed` / `kb_loaded` |
| 502 from backend | Gemini key invalid (`llm_failed kind=auth`, including HTTP 400 `API_KEY_INVALID`), provider outage (`kind=unavailable`) | backend logs; Google Cloud console |
| 429 `daily_limit_reached` | Daily model-call budget used up | backend log `rate_limited scope=daily`; raise `DAILY_REQUEST_LIMIT` only within the approved budget |
| Frequent 429 for everyone | Shared IP behind a proxy/CDN, or limits too low | `WP_KNOWLEDGE_CHAT_TRUSTED_PROXIES`; `wp_knowledge_chat_rate_limit_per_minute`; backend `RATE_LIMIT_*` |
| Many fallbacks | Question out of scope or about facts not on the site (`chat_completed outcome=out_of_scope|missing_answer_type|no_evidence reason=…`), or claim check (`outcome=unsupported_claims`) | review logs; add approved content, not looser rules |
| Widget styled oddly | Aggressive CSS optimisation / combine | Purge cache; exclude widget assets from combine |
