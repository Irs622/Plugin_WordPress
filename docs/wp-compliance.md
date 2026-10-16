# WordPress plugin-guideline review (0.2.2)

Scope: `plugin/wp-knowledge-chat`, distributed **privately** to Inpartner (ZIP upload), not through WordPress.org. The reference is the WordPress Plugin Handbook (security, enqueueing, i18n, uninstall), the WordPress Coding Standards sniffs in `tests/phpcs.xml.dist`, and the WordPress **Plugin Check** tool. Reviewed 2026-10-16 against WordPress 7.1.3 / PHP 8.5.

## Already compliant

Capability and nonce checks on every admin action, escaped output, sanitised input, prepared SQL, `defined( 'ABSPATH' )` in every file, assets enqueued with a version, `wp_remote_*` for HTTP, secrets in `wp-config.php`, `uninstall.php`, privacy exporter/eraser, no development files in the ZIP.

## Changed in 0.2.2

| # | Finding | Change |
|---|---|---|
| 1 | No `Update URI` header: a same-slug plugin on WordPress.org could be offered as an update and overwrite this private plugin | `Update URI: false` |
| 2 | Inline `<script>` and `onsubmit` in admin pages (blocked by a strict CSP, against the enqueue guideline) | `assets/js/admin.js`, enqueued on the three plugin screens only (`Admin_Page::enqueue_assets`); confirmations use `data-wkc-confirm` |
| 3 | Inline `style=""` attributes in admin pages | `assets/css/admin.css`, classes prefixed `wkc-` |
| 4 | Untranslatable strings: page categories, lead post-type label, `WP_Error` messages | wrapped in `__()`; `.pot`, `.po`, `.mo`, `.l10n.php` updated (id_ID, ko_KR) |
| 5 | Uninstall cleaned the current site only | loops over `get_sites()` on multisite |
| 6 | readme short description 156 characters (limit 150) | shortened |
| 7 | `Tested up to: 7.1` unverified | confirmed: latest release is 7.1.3 |

Tests: `AdminAndWidgetTest` asserts no inline script/handler/style and that assets load only on plugin screens; `tests/bin/multisite-check.sh` checks uninstall on a two-site network.

## Plugin Check result (built ZIP)

No security or escaping errors. What remains is specific to the WordPress.org directory and was **deliberately not changed**:

| Finding | Why it stays |
|---|---|
| `plugin_updater_detected` (Update URI header) | Wanted for a private plugin (item 1). Remove only if the plugin is ever submitted to WordPress.org. |
| `trademarked_term` ("wp" in name and slug) | WordPress.org naming rule. Renaming changes the slug, option names and hooks. |
| `WordPressVIPMinimum…SuppressFilters` (4×, `class-analytics.php`) | VIP-hosting rule; `suppress_filters` is intentional so other plugins cannot alter lead queries. |
| `load_plugin_textdomain` discouraged | Only applies to plugins hosted on WordPress.org; the bundled `.mo` files need it. |
| `NonPrefixedHooknameFound` (`litespeed_purge_all`) | Calling LiteSpeed Cache's own hook. |
| Direct DB query in `uninstall.php` | Needed to delete `_transient_wkc_*` rows by prefix; prepared. |

Running Plugin Check against the working copy also lists files under `tests/`; these are not in the ZIP. Run it on the unzipped build instead:

```bash
scripts/package-plugin.sh
unzip -q dist/wp-knowledge-chat-*.zip -d /tmp/wkc-pc
.wp-test/wp plugin install plugin-check --activate
.wp-test/wp plugin check /tmp/wkc-pc/wp-knowledge-chat
```

## If the plugin is ever submitted to WordPress.org

These would be breaking changes (stored option, `wp-config.php` constants, hooks that sites may already use), so they are listed, not done:

- Name and slug may not start with "WP"; use a distinctive prefix of at least four characters (the `wp_` / `WP_` prefix is reserved for core; `wkc_` is too short).
- Remove `Update URI`.
- Remove the Inpartner-specific defaults (approved hosts, contact URL, suggested questions) or make them settings.
- Add an "External services" section to `readme.txt` naming the AI backend and Google Gemini, with their terms and privacy links.
- Document the service that the backend depends on; the review team requires it to be explained and usable.
