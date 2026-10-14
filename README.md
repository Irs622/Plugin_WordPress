# WP Knowledge Chat

**Inpartner AI Business Consultation Assistant**: a WordPress plugin and a small Node.js backend that answer visitor questions from the approved public content of [inpartnerglobal.com](https://inpartnerglobal.com). Answers cite their sources, and visitors are directed to the official contact page when the assistant does not know.

![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)
![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B%20(tested%207.1.3)-21759b)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![Node.js 20.11+](https://img.shields.io/badge/Node.js-20.11%2B-339933)
![Version 0.1.0](https://img.shields.io/badge/version-0.1.0-informational)

> **Status: MVP, verified locally, not deployed.** All automated suites pass ([test report](docs/test-report.md)). The real Gemini model has **not** been exercised yet (no API key), and the plugin has not run on the production host or a staging copy. It is not production-ready until the staging checklist in [docs/deployment.md](docs/deployment.md) is complete.

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
- English and Bahasa Indonesia interface.
- Keyboard and screen-reader accessible; axe-core reports 0 WCAG 2.1 A/AA violations.

**For administrators**
- **WP Knowledge Chat → Settings** with four settings: Enable Chatbot, Greeting Message, Default Language, Contact URL.
- Server-side validation and a **Test connection** button.
- An off switch that removes the widget instantly without touching the rest of the site.

**Security and cost control**
- No secret in the browser or the WordPress database.
- Signed WordPress→backend requests, rate limits on both tiers and a daily request budget.
- Draft and private content can never be indexed. Source URLs are restricted to approved domains.
- Questions with no matching content get the fallback without calling the AI, so they cost nothing.

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
| Retrieval | BM25 lexical search with Indonesian→English query expansion |
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
│   ├── eval/                     65-question evaluation set and runner
│   ├── scripts/                  build-kb, push-kb, mock-gemini
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

# 2. Disposable local WordPress 7.1.3 (SQLite, no MySQL) with the plugin linked in
cd ../plugin/wp-knowledge-chat/tests
composer install && npm install
bin/setup-wp.sh                   # creates <repo>/.wp-test
../../../.wp-test/wp plugin activate wp-knowledge-chat
```

## Testing

```bash
scripts/test-all.sh               # backend, evaluation, phpcs, PHPUnit, widget, E2E + Chrome, packaging
```

| Suite | Command | Result (2026-10-14) |
|---|---|---|
| Backend | `cd backend && npm test` | 64 / 64 |
| Retrieval evaluation | `npm run eval:retrieval` | 33 / 33 answerable questions find the right page |
| Static analysis | `cd plugin/wp-knowledge-chat/tests && vendor/bin/phpcs` | 0 findings |
| Plugin (real WordPress) | `vendor/bin/phpunit` | 61 / 61 |
| Widget | `npm run test:widget` | 22 / 22 |
| End-to-end + browser | `e2e/run.sh` | 12 / 12 HTTP, 14 / 14 Chrome |

The live AI evaluation needs a Gemini key: `cd backend && npm run eval:live` (see [docs/evaluation.md](docs/evaluation.md)).

## Configuration

| Where | What |
|---|---|
| `wp-config.php` | `WP_KNOWLEDGE_CHAT_BACKEND_URL` (https) and `WP_KNOWLEDGE_CHAT_SHARED_SECRET` (≥ 32 characters) |
| WordPress admin | Enable Chatbot · Greeting Message · Default Language (`en`/`id`) · Contact URL (https, approved domain) |
| Backend environment | `GEMINI_API_KEY`, `CHAT_SHARED_SECRET`, `INDEX_API_TOKEN` + optional tuning; see [.env.example](.env.example) |
| Developer filters | `wp_knowledge_chat_should_load`, `…_suggested_questions`, `…_allowed_contact_hosts`, `…_allowed_source_hosts`, `…_allowed_origins`, `…_rate_limit_per_minute`, `…_client_ip`; see [readme.txt](plugin/wp-knowledge-chat/readme.txt) |

## Deployment

Summary (full guide with rollback and troubleshooting: [docs/deployment.md](docs/deployment.md)):

1. Deploy the backend to an HTTPS Node.js host (single instance) with its environment variables set.
2. Build the plugin ZIP: `scripts/package-plugin.sh` → `dist/wp-knowledge-chat-0.1.0.zip`.
3. On **staging**: add the two `wp-config.php` constants, upload and activate the ZIP, set the Contact URL to `https://inpartnerglobal.com/contact/`, and click **Test connection**.
4. Run `npm run eval:live` and have Inpartner review the answers.
5. Go to production only with the site owner's explicit approval.

## Documentation

| Audience | Document |
|---|---|
| Site administrators | [Admin guide](docs/admin-guide.md) |
| Developers | [Architecture](docs/architecture.md) · [Tech stack](docs/tech-stack.md) · [API contract](docs/api-contract.md) · [Backend README](backend/README.md) · [Plugin readme.txt](plugin/wp-knowledge-chat/readme.txt) · [Contributing](CONTRIBUTING.md) |
| Operations | [Deployment, rollback & troubleshooting](docs/deployment.md) · [Knowledge base](docs/knowledge-base.md) |
| Security | [Security design](docs/security.md) · [Security policy](SECURITY.md) |
| Quality | [Test report](docs/test-report.md) · [Evaluation](docs/evaluation.md) · [Plugin tests](plugin/wp-knowledge-chat/tests/README.md) · [Screenshots](docs/screenshots/) |
| Product | [PRD](prd.md) · [Changelog](CHANGELOG.md) |

## Known limitations

- Answer quality with the real Gemini model has not been measured yet.
- Each question is answered on its own: follow-ups such as "tell me more" lack context.
- The assistant knows only 5 approved pages. The Cross-Border M&A page is empty and there is no FAQ or pricing page, so many questions get the fallback.
- Backend rate limits are in memory, so run a single instance.
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
