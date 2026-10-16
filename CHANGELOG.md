# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [0.2.2] - 2026-10-16

WordPress plugin-guideline pass. Details: [docs/wp-compliance.md](docs/wp-compliance.md).

### Security
- `Update URI: false` in the plugin header, so a same-slug plugin on WordPress.org cannot overwrite this private plugin through the update check.
- Admin screens no longer use inline `<script>`, `onsubmit` or `style` attributes (CSP-friendly). Script and styles are enqueued on the plugin's three screens only.

### Changed
- Knowledge base restored to the live-site snapshot (`kb.json`, `sources.json` from `a895e24`, 5 documents). KB v2.0 text was not on the cited pages (`kb:verify-sources` 0–8%, `/faq/` 404); the snapshot passes 5/5. Company-profile facts (legal name, track record, FAQ, phone) are left out until published on the site. Evaluation labels adjusted accordingly; `kb-audit` test again asserts that no phone number is in the KB.

### Fixed
- Uninstall on multisite removed data from the main site only; it now loops over every site.
- Untranslatable admin strings (page categories, lead post-type label, configuration errors).
- Plugin readme short description was 156 characters (limit 150).

### Added
- `tests/bin/multisite-check.sh`: installs the ZIP network-wide in a two-site network and checks that uninstall cleans both sites.
- PHPUnit: admin screens contain no inline script/handler/style; admin assets load only on plugin screens.
- Held-out evaluation set refreshed with 12 new, untuned questions (see [docs/evaluation.md](docs/evaluation.md)).

### Known / not done
- The live Gemini evaluation was attempted on 2026-10-16 and could not run: the provider rejected the configured API key (`auth`). Needs a valid key.

## [0.2.1] - 2026-10-15

Audit and remediation of 0.2.0. Details and evidence: [docs/audit-2026-10-15.md](docs/audit-2026-10-15.md).

### Security
- Password-protected pages are never indexed. Content is rendered as an anonymous visitor for indexing, so members-only shortcode output cannot leak.
- Only pages an administrator synced from the Knowledge Base screen are updated automatically. Publishing a new page no longer pushes it to the AI backend.
- Lead endpoint: field length limits, a separate rate limit (3 per visitor per 10 min, 30 per site per hour), honeypot, duplicate suppression, no echo of submitted data.
- Backend: the configured `kb.json` is loaded in preference to `/tmp/wkc-kb.json`, and a corrupt file no longer falls back to another snapshot. `/api/index` returns 501 on Netlify (updates would reach one instance only). Netlify no longer publishes compiled `dist/`.
- CI: least-privilege permissions; the ZIP is scanned for credential-like strings.

### Fixed
- Trashed pages were never removed from the knowledge base (WordPress renames the slug to `…__trashed`). Slug changes now replace the old document. Failed automatic updates are recorded, shown and retried.
- KB v2.0 reopened the price and client-name gates ("pricing terms", "Project Portfolio"). Unanswerable design-set questions stopped went from 28/33 back to 100%.
- Lead storage evicted older leads after 100 submissions. Leads are now one private post each (`wkc_lead`), migrated from the `wkc_leads` option without loss.
- The `wp_mail()` result was ignored. Delivery status is now recorded, and storage failures return an error instead of success.
- Widget lead form: server errors were shown as success and the visitor's input was lost. Fields now have labels, length limits and a visible focus ring.
- Analytics stored contact details from questions verbatim. E-mails, phone numbers, links and long numbers are now redacted.

### Added
- Analytics & Leads: delete one or all leads, CSV export (formula-safe), reset metrics, notification status. WordPress privacy exporter/eraser. Filters `wp_knowledge_chat_lead_retention_days`, `…_lead_limit_per_hour`, `…_lead_notification_email`, `…_store_query_snippets`, `…_boilerplate_lines`. Actions `…_lead_mail_failed`, `…_sync_failure`.
- Scope rules: VAT/duties, capital requirements, contract review, sport (EN/ID/KO). Office-location answer type. Claim check for names absent from the evidence. Provider cooldown after quota or auth errors.
- `npm run kb:verify-sources`: checks each KB document's text against its live page.
- Evaluation: `--min-answerable`/`--min-stopped` thresholds, false-refusal count, follow-up questions with history, live `--max-model-calls`, token/cost/latency/error reporting, `human_review` fields. 17 new design items. Six contaminated held-out items moved to the design set.
- CI: held-out evaluation, design-set stop threshold, widget tests, PHP 8.5 matrix, PHP 8.1 syntax job, ZIP lifecycle check.

## [0.2.0] - 2026-10-15

### Added
- **Lead Capture & Consultation Request (FR-16)**: Optional consultative lead inquiry form inside the chat widget for ungrounded answers. POST `/wp-json/wp-knowledge-chat/v1/lead` REST route, admin email notification, and lead storage in WordPress.
- **Analytics & Consultation Leads Dashboard (FR-15)**: WordPress admin dashboard under "Analytics & Leads" displaying conversation metrics, grounded vs fallback rates, language breakdown, recent non-PII queries, and captured prospective client leads.
- **Automated Knowledge Base Synchronization (FR-14)**: Added `transition_post_status` hook to automatically push page updates to the AI backend when published/updated and delete them when trashed/unpublished.
- **Elementor & Page Builder Content Extraction**: Content extraction in `Content_Sync` now supports Elementor builder content and expands shortcodes so pages built with Elementor do not sync as empty text.
- **Clear Conversation Button**: Added header reset button (`↺`) in the chat widget allowing visitors to easily restart the conversation.
- **Structured Safe Message Bubbles**: Formats bullet lists and line breaks cleanly using DOM elements without `innerHTML` (100% XSS-safe).
- **Netlify Functions & Serverless Resilience**: Configured `included_files` in `netlify.toml` for `kb.json` bundling, updated publish directory, and added multi-path resolution in `readKnowledgeBaseFile` for container/serverless environments.
- **Retrieval Accuracy Boost**: Added regional coverage query expansions, lifting held-out answerable retrieval accuracy to **100% (25/25)**.
- **Korean (한국어)** as a third language beside English and Bahasa Indonesia: widget interface, REST error messages, backend fallback text, answer-language instruction and few-shot examples.
- **Language switcher** in the chat header (EN · ID · 한국어). The visitor's choice is remembered in `localStorage` (`wkc-lang`); the conversation is kept when switching and later questions are sent in the new language.
- **Browser-language detection**: the widget starts in the visitor's browser language when it is supported (`ko-KR` → Korean, `id`/`in` → Indonesian), otherwise in the default language.
- **Greeting per language**: three greeting fields (English, Bahasa Indonesia, 한국어). An empty field restores that language's built-in greeting.
- Settings `language_switcher` and `detect_browser_language` (both on by default).
- Admin translations: `languages/wp-knowledge-chat.pot` plus Indonesian (`id_ID`) and Korean (`ko_KR`) `.po`/`.mo`/`.l10n.php`, loaded with `load_plugin_textdomain()`; the plugin ZIP includes `languages/`.
- Korean retrieval: a curated Korean→English term map, matched as substrings because Korean attaches particles to words (서비스는, 시장에). Korean scope rules (prompt injection, investment/tax/legal/medical advice, guarantees, unrelated tasks) and answer-type rules (price, founding, headcount, statistics, clients, hours).
- Evaluation: 19 Korean questions in the design set and 7 in the held-out set.

### Changed
- Settings option: `greeting_message` is replaced by `greetings` (`en`/`id`/`ko`). Existing sites are migrated on activation or first read: a customised greeting is kept for the default language, the old built-in English greeting is replaced by each language's default.
- Widget config: `greeting` → `greetings` (map), `suggestions` is now a map per language, and `languages`, `switcher`, `detectBrowser` were added. The widget still accepts the 0.1 shape, so cached pages keep working.
- Widget CSS: Hangul-capable font stack and `word-break: keep-all` for Korean; the send button label no longer wraps.

### Fixed
- E2E: the Indonesian fallback assertion matched text that was changed in an earlier commit; the asset version is read from the plugin header instead of being hard-coded.

### Security
- Backend: the Gemini deadline now covers reading the response body; a provider that sent headers and then stalled held the request indefinitely. Provider responses are capped at 256 KiB.
- Backend: numbers, URLs, e-mail addresses and phone numbers in a model answer must appear in the retrieved evidence, otherwise the fallback is returned (a grounded answer could previously state "offices in 40 countries" or an invented price).
- Backend: deterministic scope policy and answer-type check before the model. Prompt injection, personal investment/legal/tax/medical advice, guarantees, unrelated tasks, and questions about prices, founding date, headcount, statistics, clients or office hours that the evidence cannot answer get the fallback without a model call. Markup in questions is no longer used as search terms.
- Plugin: `X-Forwarded-For` is honoured only from proxies listed in the new `WP_KNOWLEDGE_CHAT_TRUSTED_PROXIES` constant (right-most untrusted hop); previously every visitor behind a CDN shared one rate-limit bucket.
- Plugin: **Test connection** signs its health request and the backend verifies it, so a wrong shared secret is reported instead of "reachable".

### Fixed
- Backend: Gemini's invalid-key response (HTTP 400 `API_KEY_INVALID`) is now classified as `auth`, not `bad_response`.
- Backend: a provider safety block returns the fallback (200) instead of a 502.
- Backend: `DAILY_REQUEST_LIMIT` counts model calls only; questions answered without the model no longer use up the budget for everyone.
- Backend: duplicate ids in a knowledge-base file are rejected instead of silently overwriting the first entry.
- `kb:build` fails (and keeps the previous `kb.json`) when a required page cannot be fetched, is unpublished or empty; it previously wrote a snapshot without that page.
- Plugin: greeting messages lost backslashes (double `wp_unslash()`).
- Plugin: a malformed `enabled` value is rejected with an error and the saved value kept, instead of silently disabling the widget.
- Plugin: a backend URL with a query string or fragment is treated as not configured.
- Tests: `setup-wp.sh` now creates the `.wp-test/wp` wrapper used by the docs, PHPUnit and E2E, activates the plugin, and recreates the database on re-run; the E2E log review no longer matches unrelated paths that contain the plugin name.

### Changed
- Backend `package.json` license set to `GPL-2.0-or-later` to match the plugin.
- Knowledge base: lines consisting only of a phone number are excluded until Inpartner confirms which of the two published numbers is correct; the Cross-Border M&A page is marked `optional`. `data/kb.json` rebuilt (only those contact-page lines changed).
- Evaluation: offline mode now measures whether answerable questions reach the model with the right page (not only retrieval), reports why unanswerable questions were stopped, and supports `--dataset=`. Live mode counts a grounded answer to an unanswerable question as "needs review" instead of a pass, and reports retrieval hit rate, outcomes and failure rate. `faq-14` (phone number) changed to `either` with both numbers forbidden.
- Plugin: settings changes trigger a LiteSpeed Cache purge (`litespeed_purge_all`); 429 responses carry `Retry-After`; the contact-URL help text no longer names an unapproved contact page.
- Docs: staging-first deployment checklist with local/staging/production verification status; contact URL stays at the homepage default unless the owner approves a change.

### Added
- `npm run kb:validate` (offline knowledge-base audit) and `src/services/kb-audit.ts`.
- `eval/holdout.json`: 42 held-out questions written before the answerability gate and never used for tuning.
- `tests/bin/package-check.sh`: ZIP checksum, install, activate, deactivate, uninstall and reinstall on a fresh WordPress.
- Plugin action `wp_knowledge_chat_backend_failure` and filter `wp_knowledge_chat_trusted_proxies`.
- `LICENSE` (GPL-2.0-or-later), `CONTRIBUTING.md`, `SECURITY.md` and `docs/tech-stack.md` (technologies, versions, rationale).
- Rewritten `README.md` with table of contents, tech stack, test results, documentation index, license and author.
- Complete documentation set: plugin `readme.txt` (installation, configuration, FAQ, filters, privacy, uninstall), backend README, plugin test README, admin guide, evaluation guide and this changelog.

## [0.1.0] - 2026-10-14

### Added
- WordPress plugin **WP Knowledge Chat**:
  - Floating chat widget (vanilla JS/CSS) with greeting, suggested questions, loading, error/retry and fallback states, source links and contact link; English and Bahasa Indonesia interface; keyboard and screen-reader support; mobile bottom sheet.
  - Settings page (WP Knowledge Chat → Settings) with `enabled`, `greeting_message`, `language` and `contact_url`, stored in `wp_knowledge_chat_settings` and validated server-side.
  - Public REST route `POST /wp-json/wp-knowledge-chat/v1/chat` with input validation, origin check, per-visitor sliding-window rate limit, HMAC-signed forwarding and response re-validation.
  - "Test connection" admin action protected by capability and nonce checks.
  - Developer filters for page targeting, suggested questions, allowed hosts and origins, rate limit and client IP.
- Node.js/TypeScript backend with `/api/chat`, `/api/index` and `/api/health`; BM25 retrieval; Gemini integration with JSON output, grounding enforcement, deterministic fallback, timeouts, one bounded retry, per-client, global and daily limits.
- Knowledge-base tooling (`kb:build`, `kb:push`) and a reviewed snapshot of 5 approved inpartnerglobal.com pages.
- A 65-question evaluation dataset with offline retrieval and live evaluation runners.
- Test suites: backend (`node:test`), PHPUnit on real WordPress 7.1.3, jsdom widget tests, end-to-end HTTP and headless-Chrome checks, phpcs security and compatibility rules.
- Documentation: architecture, API contract, security, deployment and rollback, knowledge base, test report.

### Fixed during development
- Signature collisions for identical requests in the same second (random nonce added).
- Rate-limit burst across minute boundaries (sliding window).
- Hello Elementor button styles leaking into the widget (styles scoped under `#wkc-root`).
