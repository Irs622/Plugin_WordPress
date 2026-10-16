# Plugin tests

Everything in this folder is development tooling and is **not** included in the plugin ZIP.

| Path | Suite | Runner |
|---|---|---|
| `php/` | Integration tests against a real local WordPress | PHPUnit 12 |
| `js/` | Widget behaviour in a simulated DOM | `node:test` + jsdom |
| `e2e/` | Full stack: WordPress + backend + mock Gemini, over HTTP and in headless Chrome | `node:test`, Chrome DevTools Protocol |
| `phpcs.xml.dist` | Static analysis: WordPress security sniffs + PHP compatibility (8.1+) | PHP_CodeSniffer |
| `bin/setup-wp.sh` | Creates the disposable local WordPress | bash + WP-CLI |
| `bin/elementor-fixture.php` | Elementor-built front page + header menu for layout checks | `wp eval-file` |

## Requirements

- PHP ≥ 8.1 with `pdo_sqlite`, and Composer
- Node.js ≥ 20.11 (Node 22+ for the browser check, which uses the built-in `WebSocket`)
- Google Chrome (browser check only; set `CHROME_BIN` if it is not in the default macOS location)
- Network access the first time, to download WordPress, WP-CLI and the SQLite integration plugin

No MySQL or Docker is needed: WordPress runs on SQLite using the official SQLite Database Integration plugin.

## One-time setup

```bash
cd plugin/wp-knowledge-chat/tests
composer install
npm install
bin/setup-wp.sh                      # → <repo>/.wp-test (WordPress 7.1.3, symlinked + activated plugin, test users, `wp` wrapper)

# Required by e2e/run.sh (it asserts an Elementor-built page); reproduces the reported environment:
../../../.wp-test/wp theme install hello-elementor --version=3.5.1 --activate
../../../.wp-test/wp plugin install elementor --version=4.3.5 --activate
../../../.wp-test/wp eval-file bin/elementor-fixture.php
```

`setup-wp.sh` accepts a target directory as its first argument, and the environment variables `WP_VERSION` (default `7.1.3`) and `WP_URL` (default `http://127.0.0.1:8899`). Running it again recreates the install, including its database, from scratch.

`bin/multisite-check.sh [zip]` network-activates the ZIP in a fresh two-site multisite (`<repo>/.wp-ms-test`) and checks that uninstall removes settings, metrics, leads and transients on every site.

`bin/package-check.sh [zip]` verifies a built ZIP in a separate fresh install (`<repo>/.wp-zip-test`): checksum, no development files, install, activation defaults, deactivation keeps settings, uninstall removes option/transients/files, reinstall.

Test users (local only): `admin` / `admin-pass-123`, `editor` / `editor-pass-123`, `subscriber` / `subscriber-pass-123`.

## Running

```bash
vendor/bin/phpunit                   # PHPUnit (uses phpunit.xml.dist)
vendor/bin/phpcs                     # static analysis (uses phpcs.xml.dist)
npm run test:widget                  # jsdom widget tests
e2e/run.sh                           # full stack; needs backend built (cd backend && npm install)
```

Or run everything from the repository root with `scripts/test-all.sh`.

### PHPUnit details

- `php/bootstrap.php` loads `<repo>/.wp-test/wordpress/wp-load.php`. Override the location with `WKC_WP_DIR`. Never point it at a real site: the tests delete and rewrite the plugin option.
- `phpunit.xml.dist` sets dummy `WKC_BACKEND_URL` / `WKC_SHARED_SECRET`, which the test `wp-config.php` turns into the plugin constants.
- No real HTTP is made: the backend is simulated with the `pre_http_request` filter.
- PHPUnit is configured to **fail on any warning, notice or deprecation**.

### E2E details

`e2e/run.sh`:

1. Checks that ports 8787, 8790 and 8899 are free.
2. Builds the backend, copies `backend/data/kb.json`, and generates throwaway secrets.
3. Starts the mock Gemini (8790), the backend (8787) and PHP's built-in server with 4 workers (8899).
4. Runs `e2e/http-check.mjs`, then `e2e/browser-check.mjs`.
5. Fails if `debug.log` contains plugin entries or any log contains the generated secrets.
6. Stops all processes, including PHP worker children.

Screenshots and `results.json` go to `<repo>/docs/screenshots/`; override with `SCREENSHOT_DIR`. Service logs are written to `.wp-test/logs/`.

## Coverage summary

| Area | Where |
|---|---|
| Activation, deactivation, defaults, migration of partial/corrupt options | `SettingsTest` |
| Validation of every setting (17 malicious contact URLs, greeting rules, language enum) | `SettingsTest` |
| Capabilities, nonces, admin menu, escaping, widget loading rules, no secrets in output | `AdminAndWidgetTest` |
| REST route: signing, validation, origin, rate limit (incl. minute boundary), failure mapping, response sanitising | `RestControllerTest` |
| Widget a11y structure, keyboard, XSS-safe rendering, URL filtering, duplicate-submit guard, errors, session restore | `js/chat-widget.test.js` |
| Real options.php flow per role, nonce rejection, end-to-end answers, provider failures, rate limit | `e2e/http-check.mjs` |
| Layout/style isolation on Hello Elementor, keyboard flow, axe-core WCAG 2.1 A/AA, mobile layout | `e2e/browser-check.mjs` |

## Troubleshooting

| Problem | Fix |
|---|---|
| `WordPress test install not found` | Run `bin/setup-wp.sh` |
| `WP Knowledge Chat is not active in the test install` | `.wp-test/wp plugin activate wp-knowledge-chat` |
| `Port … is already in use` | Stop leftovers: `pkill -f dist/src/server.js; pkill -f mock-gemini.js; pkill -f "S 127.0.0.1:8899"` |
| Composer times out on IPv6 | `COMPOSER_IPRESOLVE=4 composer install` |
| Browser check cannot find Chrome | `CHROME_BIN=/path/to/chrome e2e/run.sh` |
| Rate-limit tests fail after manual testing | `.wp-test/wp transient delete --all` |
