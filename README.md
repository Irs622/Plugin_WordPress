# WP Knowledge Chat

**Inpartner AI Business Consultation Assistant**: a WordPress plugin and a small Node.js backend that answer visitor questions from the approved public content of [inpartnerglobal.com](https://inpartnerglobal.com). Answers cite their sources, and visitors are directed to the official contact page when the assistant does not know.

![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)
![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B%20(tested%207.1.3)-21759b)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![Node.js 20.11+](https://img.shields.io/badge/Node.js-20.11%2B-339933)
![Version 0.2.0](https://img.shields.io/badge/version-0.2.0-informational)

> **Status: MVP, verified locally, not deployed. Ready for staging, not for production.** All automated suites pass ([test report](docs/test-report.md)). The real Gemini model has **not** been exercised yet (no API key), and the plugin has not run on MySQL, LiteSpeed, the production host or a staging copy. It is not production-ready until the staging checklist in [docs/deployment.md](docs/deployment.md) is complete and the open content questions are answered.

## Contents

- [Features](#features)
- [How it works](#how-it-works)
- [Tech stack](#tech-stack)
- [Repository layout](#repository-layout)
- [Quick start (local development)](#quick-start-local-development)
- [Testing](#testing)
- [Configuration](#configuration)
- [Deployment](#deployment)
- [Documentation](#documentation)
- [Known limitations](#known-limitations)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)

## Features

**For visitors**
- Floating chat button and panel; on mobile the panel becomes a full-width bottom sheet.
- Greeting, suggested questions, loading indicator, and clear error messages with **Try again**.
- Answers based only on approved website pages, with **source links**.
- An honest fallback with a contact link when information is missing.
- Three languages: **English, Bahasa Indonesia and 한국어 (Korean)**. Visitors can switch language in the chat header; the widget starts in the visitor's browser language when it is supported, and the choice is remembered.
- Keyboard and screen-reader accessible; axe-core reports 0 WCAG 2.1 A/AA violations.

**For administrators**
- **WP Knowledge Chat → Settings**: Enable Chatbot, Greeting Messages (one per language), Default Language, Language Switcher, Browser Language, Contact URL.
- Admin screens translated into Bahasa Indonesia and Korean (follows the WordPress user language).
- Server-side validation and a **Test connection** button.
- An off switch that removes the widget instantly without touching the rest of the site.

**Security and cost control**
- No secret in the browser or the WordPress database.
- Signed WordPress→backend requests, rate limits on both tiers and a daily request budget.
- Draft and private content can never be indexed. Source URLs are restricted to approved domains.
- Questions the site cannot answer (no matching content, a fact type the pages do not contain such as prices, or out-of-scope requests such as investment advice or prompt injection) get the fallback without calling the AI, so they cost nothing.
- Answers containing numbers, links, e-mail addresses or phone numbers that are not on the cited pages are replaced by the fallback.

## How it works

```
 Visitor ──▶ Chat widget ──▶ WordPress REST route ──(HMAC-signed HTTPS)──▶ Node.js backend ──▶ Google Gemini
            (vanilla JS)     /wp-json/wp-knowledge-chat/v1/chat            /api/chat
                             • validate, rate-limit                         • verify signature, rate-limit
                             • origin check                                 • BM25 search in knowledge base
                             • re-check answer + links ◀─────────────────── • no match → fallback (no AI call)
                                                                            • AI answers from sources only
                                                                            • validate answer, attach sources
```

1. A visitor asks a question in the widget.
2. WordPress validates it and forwards it, signed, to the backend. The visitor's IP address is never sent.
3. The backend searches the knowledge base: a reviewed snapshot of approved inpartnerglobal.com pages.
4. If relevant content is found, Gemini answers using **only** that content and names the source ids it used. The backend turns those ids into page links.
5. WordPress checks the answer and links again and returns them to the widget.

Details: [docs/architecture.md](docs/architecture.md).

## Tech stack

| Part | Technology |
|---|---|
| WordPress plugin | PHP 8.1+, WordPress 6.4+ APIs (Settings, Options, REST, HTTP, Transients) |
| Chat widget | Vanilla JavaScript + CSS, no framework, no build step |
| Backend | Node.js 20.11+, TypeScript 5.9, built-in `node:http`/`node:crypto`/`fetch`; **zero runtime dependencies** |
| AI | Google Gemini API (`gemini-2.5-flash` by default) |
| Retrieval | BM25 lexical search with Indonesian→English and Korean→English query expansion |
| Knowledge base | JSON snapshot built from the site's public WordPress REST API |
| Testing | `node:test`, PHPUnit 12, jsdom, headless Chrome + axe-core, PHP_CodeSniffer with WPCS and PHPCompatibility, WP-CLI, SQLite Database Integration |

Full list with versions and the reasons for each choice: [docs/tech-stack.md](docs/tech-stack.md).

## Repository layout

```
.
├── plugin/wp-knowledge-chat/     WordPress plugin
│   ├── wp-knowledge-chat.php     bootstrap
│   ├── includes/                 settings, admin page, REST route, backend client
│   ├── assets/                   chat-widget.js, chat-widget.css
│   ├── readme.txt                WordPress plugin readme
│   ├── uninstall.php
│   └── tests/                    PHPUnit, jsdom, E2E, phpcs (excluded from the ZIP)
├── backend/                      Node.js + TypeScript AI service
│   ├── src/                      server, routes, services, middleware
│   ├── data/                     sources.json (approved pages), kb.json (snapshot)
│   ├── eval/                     84-question design set, 49-question held-out set (EN/ID/KO), runner
│   ├── scripts/                  build-kb, validate-kb, push-kb, mock-gemini
│   └── tests/
├── docs/                         project documentation and screenshots
├── scripts/                      package-plugin.sh, test-all.sh
├── .env.example                  backend configuration template
├── CHANGELOG.md · CONTRIBUTING.md · SECURITY.md · LICENSE
└── prd.md                        product requirements document
```

## Quick start (local development)

Requirements: Node.js ≥ 20.11, PHP ≥ 8.1 with `pdo_sqlite`, Composer. Google Chrome is needed for browser tests only.

```bash
git clone https://github.com/Irs622/Plugin_WordPress.git
cd Plugin_WordPress

# 1. Backend
cd backend
npm install
cp ../.env.example .env           # fill in GEMINI_API_KEY; create secrets with: npm run secret
npm run build && npm run start:local
curl http://127.0.0.1:8787/api/health

# No Gemini key yet? Use the local stand-in (deterministic, not a real model):
#   npm run mock:gemini   and set GEMINI_API_BASE_URL=http://127.0.0.1:8790 in .env

# 2. Disposable local WordPress 7.1.3 (SQLite, no MySQL) with the plugin linked in and activated
cd ../plugin/wp-knowledge-chat/tests
composer install && npm install
bin/setup-wp.sh                   # creates <repo>/.wp-test (and its `wp` wrapper)
# needed for e2e/run.sh: Hello Elementor + Elementor (see tests/README.md)
```

## Testing

```bash
scripts/test-all.sh               # backend, KB validation, evaluation, phpcs, PHPUnit, widget, E2E + Chrome, packaging + ZIP lifecycle
```

| Suite | Command | Result (2026-10-15) |
|---|---|---|
| Backend | `cd backend && npm test` | 104 / 104 |
| Knowledge base | `npm run kb:validate` | 0 errors (1 warning: optional M&A page empty) |
| Offline evaluation, design set | `npm run eval:retrieval` | 43 / 43 answerable reach the model with the right page; 33 / 33 unanswerable stopped before the model |
| Offline evaluation, held-out set | `node dist/eval/run-eval.js --mode=retrieval --dataset=eval/holdout.json` | 21 / 23 answerable; 20 / 26 unanswerable stopped |
| Static analysis | `cd plugin/wp-knowledge-chat/tests && vendor/bin/phpcs` | 0 findings |
| Plugin (real WordPress) | `vendor/bin/phpunit` | 80 / 80 |
| Widget | `npm run test:widget` | 30 / 30 |
| End-to-end + browser | `e2e/run.sh` | 12 / 12 HTTP, 15 / 15 Chrome (incl. Korean switch + axe) |
| ZIP lifecycle | `bin/package-check.sh` | install, activate, deactivate, uninstall, reinstall |

The live AI evaluation needs a Gemini key: `cd backend && npm run eval:live` (see [docs/evaluation.md](docs/evaluation.md)).

## Configuration

| Where | What |
|---|---|
| `wp-config.php` | `WP_KNOWLEDGE_CHAT_BACKEND_URL` (https, no query string) and `WP_KNOWLEDGE_CHAT_SHARED_SECRET` (≥ 32 characters); optional `WP_KNOWLEDGE_CHAT_TRUSTED_PROXIES` behind a CDN/reverse proxy |
| WordPress admin | Enable Chatbot · Greeting Messages (`en`/`id`/`ko`) · Default Language (`en`/`id`/`ko`) · Language Switcher · Browser Language · Contact URL (https, approved domain) |
| Backend environment | `GEMINI_API_KEY`, `CHAT_SHARED_SECRET`, `INDEX_API_TOKEN` + optional tuning; see [.env.example](.env.example) |
| Developer filters | `wp_knowledge_chat_should_load`, `…_suggested_questions`, `…_allowed_contact_hosts`, `…_allowed_source_hosts`, `…_allowed_origins`, `…_rate_limit_per_minute`, `…_client_ip`, `…_trusted_proxies`; action `wp_knowledge_chat_backend_failure`; see [readme.txt](plugin/wp-knowledge-chat/readme.txt) |

## Deployment

Summary (full guide with rollback and troubleshooting: [docs/deployment.md](docs/deployment.md)):

1. Deploy the backend to an HTTPS Node.js host (single instance) with its environment variables set.
2. Build the plugin ZIP: `scripts/package-plugin.sh` → `dist/wp-knowledge-chat-0.2.0.zip`.
3. On **staging**: verify the ZIP checksum, add the `wp-config.php` constants, upload and activate the ZIP, and click **Test connection**. Keep the Contact URL at the homepage default unless the owner approves a dedicated contact page.
4. Run `npm run eval:live` against the staging backend with a real Gemini key and have Inpartner review the answers.
5. Go to production only with the site owner's explicit approval.

## Documentation

| Audience | Document |
|---|---|
| Site administrators | [Admin guide](docs/admin-guide.md) |
| Developers | [Architecture](docs/architecture.md) · [Tech stack](docs/tech-stack.md) · [API contract](docs/api-contract.md) · [Backend README](backend/README.md) · [Plugin readme.txt](plugin/wp-knowledge-chat/readme.txt) · [Contributing](CONTRIBUTING.md) |
| Operations | [Deployment, rollback & troubleshooting](docs/deployment.md) · [Knowledge base](docs/knowledge-base.md) |
| Security | [Security design](docs/security.md) · [Security policy](SECURITY.md) |
| Quality | [Audit report](docs/audit-report.md) · [Test report](docs/test-report.md) · [Evaluation](docs/evaluation.md) · [Plugin tests](plugin/wp-knowledge-chat/tests/README.md) · [Screenshots](docs/screenshots/) |
| Product | [PRD](prd.md) · [Changelog](CHANGELOG.md) |

## Known limitations

- Answer quality with the real Gemini model has not been measured yet.
- Each question is answered on its own: follow-ups such as "tell me more" lack context.
- The assistant knows only 5 approved pages. The Cross-Border M&A page is empty and there is no FAQ or pricing page, so many questions get the fallback.
- Backend rate limits and the replay cache are in memory, so run a single instance.
- Out-of-scope detection is keyword-based (English/Indonesian/Korean) and can be evaded by rephrasing; the model and the claim check are the second line.
- Korean and Indonesian questions are matched against the English pages through curated term maps, not translation. Questions using words outside the map get the fallback; add terms when the evaluation shows misses.
- The assistant gives no phone number until Inpartner confirms which of the two published numbers is correct.
- Not yet tested with MySQL, LiteSpeed Cache or on real mobile devices.

The full list is in the [test report](docs/test-report.md#known-limitations).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, branch and commit conventions, coding standards and the release process.

## Security

Please report vulnerabilities privately as described in [SECURITY.md](SECURITY.md). Do not open public issues for security problems.

## License

Copyright (C) 2026 irsalshydiq.

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version. See [LICENSE](LICENSE).

WordPress plugins are required to be GPL-compatible because they extend WordPress, which is GPL-licensed.

## Author

**irsalshydiq**, built for [Inpartner Global](https://inpartnerglobal.com).
