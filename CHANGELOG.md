# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- Backend `package.json` license set to `GPL-2.0-or-later` to match the plugin.

### Added
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
