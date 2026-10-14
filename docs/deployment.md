# Deployment

> Nothing in this repository has been deployed. Production (inpartnerglobal.com) was not modified. Every step below needs explicit authorisation from the site owner, and staging comes first.

## Prerequisites

| Item | Needed for | Status |
|---|---|---|
| Gemini API key (Google AI Studio / Cloud) with billing limits set | backend | **not provided**; required before live testing |
| A host that runs Node.js ≥ 20.11 with HTTPS (e.g. Cloud Run, Render, Railway, Fly.io, a small VPS behind Caddy/Nginx) | backend | not chosen |
| Staging copy of the WordPress site | plugin acceptance | not available to us |
| Write access to `wp-config.php` on staging/production | plugin config | owner action |
| Confirmed contact URL, approved content list, language scope | config | see open questions in the test report |

The shared host only needs outbound HTTPS from PHP to the backend (`wp_remote_post`). No Node.js, SSH, cron workers or Docker are needed on the WordPress host.

## 1. Deploy the backend

```bash
cd backend
npm ci
npm run build
npm test                          # optional but recommended on the build machine
npm prune --omit=dev              # runtime has zero dependencies
# Provide env vars from your host's secret manager (see ../.env.example):
#   GEMINI_API_KEY, CHAT_SHARED_SECRET, INDEX_API_TOKEN, HOST=0.0.0.0 (PaaS), PORT
npm start                          # node dist/src/server.js
```

- Generate secrets with `npm run secret` (64 hex chars). `CHAT_SHARED_SECRET` and `INDEX_API_TOKEN` must differ.
- Ship `data/kb.json` with the build, or populate it after boot with `npm run kb:push`. Without it, `/api/health` returns 503 and chat returns 503 (the website is unaffected; the widget shows an error).
- Terminate TLS in front of the service. The backend expects HTTPS from WordPress; plain http is accepted by the plugin only for `localhost`.
- **Run a single instance.** Rate limits and the replay cache are in-memory. For several instances, move them to a shared store (Redis) first.
- For persistence of `/api/index` updates across deploys, mount `data/` on a persistent volume, or treat `kb.json` in git as the source of truth and redeploy.
- Health check: `GET /api/health` (200 = ready).
- Logs are JSON lines on stdout and contain no message text.

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

## 2. Install the plugin (staging first)

1. Build the ZIP: `scripts/package-plugin.sh` → `dist/wp-knowledge-chat-0.1.0.zip` (+ `.sha256`).
2. **Back up** the staging database and `wp-content/plugins` (host backup or a plugin such as UpdraftPlus).
3. Add to `wp-config.php` *above* "That's all, stop editing":
   ```php
   define( 'WP_KNOWLEDGE_CHAT_BACKEND_URL', 'https://chat-api.example.com' );
   define( 'WP_KNOWLEDGE_CHAT_SHARED_SECRET', '…same as CHAT_SHARED_SECRET…' );
   ```
4. Plugins → Add New → Upload Plugin → choose the ZIP → Activate.
5. WP Knowledge Chat → Settings:
   - Set **Contact URL** to `https://inpartnerglobal.com/contact/`. The page exists; the PRD default is the homepage.
   - Choose the language, then **Test connection** (should report "reachable and … ready").
6. Optional: restrict pages in a small mu-plugin or the theme's `functions.php`:
   ```php
   add_filter( 'wp_knowledge_chat_should_load', fn( $load ) => $load && ( is_front_page() || is_page( array( 'about-us', 'contact' ) ) ) );
   ```
7. If the site sits behind Cloudflare or another proxy, configure the client-IP filter (see security.md) so rate limits are per visitor.

### LiteSpeed Cache notes

- The widget reads no per-user data, so cached pages are safe to serve. The chat REST route is POST and is not cached.
- If JS/CSS combine or minify is enabled, test the widget after purging. Exclude `chat-widget.js` from "JS Combine" if it misbehaves. It already loads `defer` in the footer.
- Purge the page cache after changing settings (greeting/language/contact URL are embedded in the page).

## 3. Staging acceptance checklist

- [ ] Plugin activates without PHP notices (`WP_DEBUG_LOG` on staging)
- [ ] Widget opens/closes on desktop and mobile; header menu, Elementor sections and popups still work
- [ ] Settings save; invalid values rejected; non-admins cannot access the page
- [ ] Chat answers with source links to inpartnerglobal.com; unsupported questions show the fallback and contact link
- [ ] Stopping the backend shows a friendly error; the rest of the site keeps working
- [ ] `npm run eval:live` run against the staging backend; results reviewed by the Inpartner content owner
- [ ] View-source of a page: no backend URL, secret or API key

## 4. Rollback

| Situation | Action | Effect |
|---|---|---|
| Widget misbehaving | Settings → untick **Enable Chatbot** → Save (purge cache) | Widget no longer loaded; chat route returns 503 |
| Plugin problem | Plugins → Deactivate (or rename `wp-content/plugins/wp-knowledge-chat` via FTP) | Plugin code not loaded; settings kept |
| Remove completely | Deactivate → Delete | `uninstall.php` deletes the option and plugin transients |
| Backend problem | Stop or roll back the backend deployment | Widget shows "unavailable" errors; site unaffected |
| Bad knowledge base | Redeploy the previous `data/kb.json` or `npm run kb:push -- --file=<previous>` | Index replaced atomically |
| Leaked secret | Rotate `CHAT_SHARED_SECRET` (backend env + `wp-config.php` together) and/or `INDEX_API_TOKEN`; rotate the Gemini key in Google Cloud | Old signatures/tokens rejected immediately |

## 5. Troubleshooting

| Symptom | Likely cause | Check |
|---|---|---|
| Widget not visible | Disabled, backend constants missing/invalid (non-https URL, secret < 32 chars), page-targeting filter, Elementor preview | Settings page "AI backend" box; view-source for `chat-widget.js` |
| "Assistant unavailable" on every question | Backend down, wrong URL, secret mismatch (backend logs `chat_auth_failed reason=invalid`), clock skew > 5 min (`reason=expired`), outbound HTTPS blocked by host | *Test connection*; backend logs |
| Health 503 | `data/kb.json` missing or invalid | backend log `kb_load_failed` / `kb_loaded` |
| 502 from backend | Gemini key invalid/quota (`llm_failed kind=auth|rate_limited`) | backend logs; Google Cloud quota page |
| Frequent 429 | Shared IP behind a proxy, or limits too low | `wp_knowledge_chat_client_ip` / `wp_knowledge_chat_rate_limit_per_minute` filters; backend `RATE_LIMIT_*` |
| Widget styled oddly | Aggressive CSS optimisation / combine | Purge cache; exclude widget assets from combine |
