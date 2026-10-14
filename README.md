# WP Knowledge Chat

**Inpartner AI Business Consultation Assistant**: a WordPress plugin plus a small Node.js backend that answers visitor questions from approved public content on [inpartnerglobal.com](https://inpartnerglobal.com), cites its sources, and points visitors to the contact page when it doesn't know.

> **Status: MVP, locally verified, not deployed.** All automated suites pass locally (see [docs/test-report.md](docs/test-report.md)). The real Gemini model has **not** been exercised (no API key yet), and the plugin has not run on the production host or a staging copy. It is not production-ready until the staging checklist in [docs/deployment.md](docs/deployment.md) is complete.

```
Browser widget ──▶ WordPress REST proxy ──(HMAC-signed)──▶ Node backend ──▶ Gemini
                   (validation, rate limit,               (auth, limits, BM25 retrieval,
                    origin check, re-validation)            grounding checks, fallback)
```

| | |
|---|---|
| Plugin ZIP | `dist/wp-knowledge-chat-0.1.0.zip` (build: `scripts/package-plugin.sh`) |
| Plugin source | [plugin/wp-knowledge-chat/](plugin/wp-knowledge-chat/) |
| Backend source | [backend/](backend/) |
| Docs | [architecture](docs/architecture.md) · [API contract](docs/api-contract.md) · [security](docs/security.md) · [deployment & rollback](docs/deployment.md) · [knowledge base](docs/knowledge-base.md) · [evaluation](docs/evaluation.md) · [test report](docs/test-report.md) · [changelog](CHANGELOG.md) |
| Guides | [admin guide](docs/admin-guide.md) (site administrators) · [backend README](backend/README.md) · [plugin readme.txt](plugin/wp-knowledge-chat/readme.txt) · [plugin tests README](plugin/wp-knowledge-chat/tests/README.md) |
| Screenshots | [docs/screenshots/](docs/screenshots/) (Hello Elementor + Elementor, desktop and mobile) |

## Features

- Floating, responsive, keyboard-accessible chat widget (vanilla JS/CSS, ~27 KB, deferred). Greeting, suggested questions, loading/error/fallback states, source links, contact link, English and Bahasa Indonesia UI.
- Admin page **WP Knowledge Chat → Settings** with the four PRD settings (`enabled`, `greeting_message`, `language`, `contact_url`) in the option `wp_knowledge_chat_settings`, validated server-side.
- No secrets in the browser or the database. The Gemini key lives only on the backend; the WordPress↔backend secret lives in `wp-config.php`.
- Grounded answers: retrieval → model cites source ids → backend maps them to approved pages. No evidence means a deterministic fallback without calling the model.
- Rate limits and size limits on both tiers; a daily request budget caps provider cost.

## Repository layout

```
plugin/wp-knowledge-chat/     WordPress plugin (shipped files + tests/ which is excluded from the ZIP)
backend/                      Node.js + TypeScript service (zero runtime dependencies)
  data/sources.json           approved page allowlist + exclusions
  data/kb.json                reviewed knowledge-base snapshot (5 pages)
  eval/                       65-question evaluation set and runner
scripts/                      package-plugin.sh, test-all.sh
docs/                         architecture, API, security, deployment, KB, test report
.env.example                  backend configuration template (no real values)
```

## Quick start (local development)

Requirements: Node ≥ 20.11, PHP ≥ 8.1 with `pdo_sqlite`, Composer, Google Chrome (browser tests only).

```bash
# Backend
cd backend
npm install
cp ../.env.example .env           # fill in GEMINI_API_KEY; generate secrets with: npm run secret
npm run build && npm run start:local
curl http://127.0.0.1:8787/api/health

# No Gemini key? Use the local stand-in (deterministic, not a model):
npm run mock:gemini &             # then set GEMINI_API_BASE_URL=http://127.0.0.1:8790 and any GEMINI_API_KEY

# Disposable local WordPress 7.1.3 (SQLite, no MySQL) with the plugin symlinked in
cd ../plugin/wp-knowledge-chat
tests/bin/setup-wp.sh             # creates ../../.wp-test
../../.wp-test/wp plugin activate wp-knowledge-chat
```

## Tests

```bash
# one-time tooling
(cd backend && npm install)
(cd plugin/wp-knowledge-chat/tests && composer install && npm install)
plugin/wp-knowledge-chat/tests/bin/setup-wp.sh
# optional: Hello Elementor + Elementor fixture, as used for the reported results
.wp-test/wp theme install hello-elementor --version=3.5.1 --activate
.wp-test/wp plugin install elementor --version=4.3.5 --activate
.wp-test/wp eval-file plugin/wp-knowledge-chat/tests/bin/elementor-fixture.php

scripts/test-all.sh               # everything: backend, eval, phpcs, PHPUnit, jsdom, E2E + Chrome, package
```

Individual suites: `cd backend && npm test` · `npm run eval:retrieval` · `npm run eval:live` (needs a real key) · `cd plugin/wp-knowledge-chat/tests && vendor/bin/phpunit` · `npm run test:widget` · `e2e/run.sh`.

## Configuration summary

| Where | Setting |
|---|---|
| `wp-config.php` | `WP_KNOWLEDGE_CHAT_BACKEND_URL` (https), `WP_KNOWLEDGE_CHAT_SHARED_SECRET` (≥ 32 chars) |
| WP admin | Enable Chatbot, Greeting Message, Default Language (`en`/`id`), Contact URL (https, approved domain) |
| Backend env | see [.env.example](.env.example) |
| Filters | `wp_knowledge_chat_should_load`, `…_suggested_questions`, `…_allowed_contact_hosts`, `…_allowed_source_hosts`, `…_allowed_origins`, `…_rate_limit_per_minute`, `…_client_ip` |

## Before going live

1. Get a Gemini API key, deploy the backend (HTTPS, single instance), and run `npm run eval:live`. Have Inpartner review the per-question report.
2. Fix the content issues listed in [docs/knowledge-base.md](docs/knowledge-base.md) (placeholder phone number, empty M&A page, conflicting phone numbers).
3. Install on **staging**, set the Contact URL to `https://inpartnerglobal.com/contact/`, and run the staging checklist.
4. Get explicit approval before touching production.
