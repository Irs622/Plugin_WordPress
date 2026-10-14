# Security

## Trust boundaries

| Boundary | Untrusted input | Controls |
|---|---|---|
| Browser → WordPress | message, language, `Origin`, anything in the page | Length/type validation, control-char stripping, enum check, same-origin check, per-IP sliding-window rate limit, no cookies sent (`credentials: 'omit'`) |
| WordPress → backend | WordPress itself is *not* trusted for content | HMAC signature (timestamp + random nonce + client id + body), ±5 min window, replay cache, independent validation and rate limits on the backend |
| Backend → Gemini | provider responses | JSON schema requested, then parsed and validated; ids mapped to KB entries; unsafe output (key patterns, prompt text, markup) → fallback |
| Backend → WordPress → browser | backend responses | WordPress re-sanitises answer text, re-checks every source URL against the host allowlist, downgrades `grounded` without sources |
| Admin settings | form posts | `manage_options` (options.php and page render), Settings API nonce, server-side validation per field, contextual escaping |
| Knowledge base | website content (treated as data, not instructions) | Only allowlisted page IDs; `status=publish` required; host allowlist; content wrapped in sanitised delimiters in the prompt |

## Control checklist (PRD §9)

| Requirement | Implementation | Evidence |
|---|---|---|
| Capability checks | `manage_options` on menu, page render (`wp_die` 403), admin-post handler; options.php enforces group capability | PHPUnit `AdminAndWidgetTest`; E2E: subscriber and editor get 403, settings unchanged |
| Nonce validation | Settings API nonce on options.php; `check_admin_referer` on *Test connection* | E2E: admin with bad nonce → 403; PHPUnit: missing/wrong/other-action nonce → 403 |
| Input validation | WP route + backend validate independently (type, length ≤500, enum) | PHPUnit `RestControllerTest`, backend `api.test.ts` |
| Output escaping | Admin: `esc_html`/`esc_attr`/`esc_textarea`/`esc_url`. Inline config: `wp_json_encode` with `JSON_HEX_*`. Widget: `textContent` only, no `innerHTML` | PHPUnit escaping tests; jsdom tests (XSS payloads stay text); static grep test for `innerHTML` |
| SQL safety | No custom tables; only `uninstall.php` uses `$wpdb->prepare()` with `esc_like` | phpcs `WordPress.DB.PreparedSQL*`: 0 findings |
| Rate limiting | WP 6/min/IP (sliding window). Backend 8/min/client, 60/min global, 1000/day (all configurable) | PHPUnit, backend tests, E2E 7th request → 429 |
| Request-size limits | WP 500 chars; backend chat 8 KiB, index 2 MiB, enforced while streaming | backend tests (413) |
| Timeouts | Browser 30 s, WP→backend 20 s, backend→Gemini 12 s total (1 retry), server request/header timeouts | backend timeout tests; E2E slow provider → friendly 503 |
| Secret management | Gemini key and tokens only in backend env; WP secret in `wp-config.php`; nothing in options or frontend | E2E: page HTML, settings page and logs checked for secrets; PHPUnit: option and inline config contain none |
| Safe URL handling | Contact URL: https, approved host, no credentials, no custom port, no whitespace/backslash. Never fetched. Source URLs validated in backend, WP and widget | PHPUnit data provider (17 malicious URL cases); jsdom URL tests |
| CORS | Backend sends **no** CORS headers (browsers cannot call it); WP route rejects foreign `Origin` | backend + E2E tests |
| Error handling | Generic, localised messages; provider/backend details only in structured server logs (no content) | tests assert no `cURL`, IPs, paths, ports, secrets in responses |
| XSS | See output escaping; answer text also tag-stripped server-side | jsdom + PHPUnit + backend tests |
| Prompt injection | No-evidence → no LLM call; delimiter sanitisation; rules in system instruction; model cannot supply URLs; output leak filter | backend tests; 9 injection items in eval set |
| Unauthorised indexing | Bearer token (timing-safe), separate from chat secret; publish-only; host allowlist; all-or-nothing; no URL fetching | backend `api.test.ts` |
| Private content | `status` must be `publish` at build and index time; build fetches only allowlisted IDs via the public REST API | backend tests (draft/private rejected) |

Static analysis: `phpcs` with `WordPress.Security`, `WordPress.DB.PreparedSQL*` and `PHPCompatibilityWP` (PHP 8.1+) reports **0 findings** on the shipped plugin code. The ruleset was checked against a deliberately insecure sample to confirm it fires.

## Data handling and privacy

- **Stored by WordPress:** the four settings; short-lived rate-limit transients keyed by an HMAC of the IP (no raw IP stored).
- **Sent to the backend:** question text, language, a salted one-way client id. **Never** the IP, cookies or user identity.
- **Sent to Google Gemini:** the question plus retrieved public website excerpts. Under Google's API terms, data handling depends on the account tier. Review whether paid-tier "no training" terms are required before launch (PRD §12 Privacy).
- **Logged by the backend:** event name, outcome, language, message *length*, latency, error kind. Never message text, IPs or secrets.
- **Browser:** conversation in `sessionStorage` only (cleared when the tab closes).
- **Lead capture:** not implemented. If added, it needs consent, a retention period and access controls first (PRD §11).

## Residual risks / not covered

- Rate limits in the backend are per-process memory. Several instances or restarts reset counters. WordPress transients without a persistent object cache add database writes per chat request.
- The WP per-IP limit uses `REMOTE_ADDR`. Behind a CDN or proxy, every visitor may share an IP until `wp_knowledge_chat_client_ip` is filtered to read a header set **only** by the trusted proxy.
- LLM behaviour can only be reduced, not eliminated, by prompt rules. Unanswerable questions that share keywords with the site do reach the model (11 of 25 in the offline evaluation) and depend on it declining. A live evaluation with the real model has **not** been run yet.
- No WAF/bot protection. Sustained abuse is bounded by the daily budget, which also blocks legitimate users once reached.
