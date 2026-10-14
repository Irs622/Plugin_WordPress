# Contributing

How to work on this project.

## Setup

See [README.md → Quick start](README.md#quick-start-local-development) and [plugin/wp-knowledge-chat/tests/README.md](plugin/wp-knowledge-chat/tests/README.md). In short:

```bash
(cd backend && npm install)
(cd plugin/wp-knowledge-chat/tests && composer install && npm install && bin/setup-wp.sh)
.wp-test/wp plugin activate wp-knowledge-chat
scripts/test-all.sh
```

## Workflow

1. Branch from `main`: `feat/<topic>`, `fix/<topic>`, `docs/<topic>`.
2. Make the change, with tests for any behaviour change.
3. Run `scripts/test-all.sh`. All suites must pass.
4. Update the docs affected by the change (API contract, security, settings, `CHANGELOG.md` under *Unreleased*).
5. Open a pull request describing what changed, why, and how it was tested.

Never deploy to production, or run tests against the live site, without the site owner's explicit approval.

## Commit messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add page targeting setting
fix: keep rate-limit window across minute boundary
docs: document LiteSpeed cache settings
test: cover invalid contact URLs
chore: bump typescript
```

## Coding standards

**PHP (plugin)**
- WordPress coding style: tabs, `array()` syntax, Yoda conditions, and docblocks on public methods.
- Everything lives in the `WP_Knowledge_Chat` namespace; files are named `includes/class-<name>.php`.
- Sanitise input on the way in and escape on the way out (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`). Check capabilities and nonces for every admin action.
- No secrets in options or frontend output.
- `vendor/bin/phpcs` (in `tests/`) must report 0 findings.
- Must work on PHP 8.1+ without deprecations: PHPUnit fails on any notice.

**JavaScript (widget)**
- Vanilla JS, no dependencies, no build step. Keep the syntax runnable in all current browsers.
- Never use `innerHTML`, `insertAdjacentHTML` or `document.write` for content (a test enforces this). Build DOM nodes with `textContent`.
- Links must go through `safeUrl()` (https plus an allowed host).

**CSS**
- Scope every selector under `#wkc-root`. Avoid `!important` except in the screen-reader-only helper.

**TypeScript (backend)**
- `strict` mode and ES modules, with `.js` extensions in imports.
- No new runtime dependencies without a documented reason.
- Never log message text, IP addresses, secrets or provider payloads.
- Validate all external input and return errors as `{ error: { code, message } }` without internal details.

## Knowledge-base changes

Content changes need approval from the Inpartner content owner. Follow [docs/knowledge-base.md](docs/knowledge-base.md):

1. Edit `backend/data/sources.json`.
2. Run `npm run kb:build`.
3. Review the `git diff` of `kb.json`.
4. Run `npm run eval:retrieval`.

## Releases

1. Bump the version in `plugin/wp-knowledge-chat/wp-knowledge-chat.php`: both the header `Version:` and `WP_KNOWLEDGE_CHAT_VERSION`.
2. Update `Stable tag` in `readme.txt` and `version` in `backend/package.json`.
3. Move the *Unreleased* entries in `CHANGELOG.md` to the new version.
4. Run `scripts/test-all.sh`, then `scripts/package-plugin.sh`.
5. Tag `vX.Y.Z` and attach `dist/wp-knowledge-chat-X.Y.Z.zip` with its `.sha256` to the release.
