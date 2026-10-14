# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
