# Test report: WP Knowledge Chat 0.1.0 (+ unreleased audit fixes)

**Date:** 2026-10-14 · **Command:** `scripts/test-all.sh` (exit 0; the final run hit a Chrome-startup flake, see Known limitations, and `e2e/run.sh` was re-run twice, both passing) · **Environment:** local only, nothing deployed. Findings and fixes: [audit-report.md](audit-report.md).

## Test environment (actual, not the reported production baseline)

| Component | Version used | Production baseline (reported, not verified by us) |
|---|---|---|
| WordPress | 7.1.3 (official ZIP), SQLite Database Integration instead of MySQL | 7.1.3 (generator tag confirmed on the live site), MySQL 8.0.46 |
| PHP | 8.5.5 CLI, built-in server with 4 workers | 8.5.11, Apache/LiteSpeed |
| Theme / builder | Hello Elementor 3.5.1, Elementor 4.3.5 (Elementor-built front page and header menu) | same versions (Elementor version confirmed on the live site) |
| Node.js | 25.9.0 (package requires ≥ 20.11) | external host, not chosen |
| Browser | Google Chrome headless (CDP) | — |
| AI provider | **Local mock Gemini** (`backend/scripts/mock-gemini.ts`) | Gemini API, **no key available** |

Not covered by the local environment: MySQL, LiteSpeed/Apache rewrite and cache behaviour, PHP 8.5.11 and the 40 MB memory limit, PHP 8.1–8.4 at runtime, real network latency, the real Gemini model, real mobile devices and screen readers.

## Results: before and after

| Suite | Baseline (start of audit) | After remediation |
|---|---|---|
| Backend `npm test` (node:test) | 64 / 64 | **95 / 95** |
| Knowledge base `npm run kb:validate` | — (did not exist); the committed snapshot fails it: disputed phone line present | **0 errors**, 1 warning (optional M&A page empty) |
| Offline evaluation, design set (65) | answerable retrieved 33/33; unanswerable stopped before model **14/25** | answerable reach the model with the right page **32/32**; unanswerable stopped **25/25** |
| Offline evaluation, held-out set (42, new) | answerable retrieved 18/20; unanswerable stopped **8/22** | answerable 18/20 (0 refused by the gate); unanswerable stopped **16/22** |
| `phpcs` (WordPress.Security, PreparedSQL, PHPCompatibilityWP 8.1+) | 0 findings | **0 findings** |
| PHPUnit 12.5 on real WordPress 7.1.3 | 61 tests (after fixing the setup script, see D17) | **72 tests, 325 assertions**; fails on any PHP warning, notice or deprecation, none occurred |
| Widget (jsdom) | 22 / 22 | **22 / 22** |
| End-to-end HTTP (real WP + real backend + mock Gemini) | 11 / 12 → 12 / 12 after installing the documented Elementor fixture | **12 / 12** (connection test now asserts the shared secret is accepted by the real backend) |
| Headless Chrome (desktop 1366×900, mobile 390×844, axe-core WCAG 2.1 A/AA) | 14 / 14 | **14 / 14** |
| Log review (debug.log, backend/PHP/mock logs) | false positive (path contained the plugin name) | **clean**: no plugin notices, no secrets |
| Package | ZIP built; lifecycle checked manually in the previous report | ZIP built (sha256 `9b04436f…70df` for this tree) and **`package-check.sh` passed**: checksum, no dev files, install from ZIP, activation defaults, deactivation keeps settings, uninstall removes option/transients/files, reinstall |

The baseline needed three test-infrastructure fixes before it could run from a clean checkout (D17 in the audit report). They change no assertions.

### Regression tests prove the fixes

The new tests were run against the **original** source:

- Backend: 8 new tests failed and the stalled-body test hung until the per-test timeout; all pass after the fix. The persist-rollback and 429-retry tests passed on both (they add coverage for behaviour that was already correct).
- PHPUnit: all 11 new tests failed or errored on the original plugin code; all pass after the fix.

### Assertions changed

| Test | Change | Reason |
|---|---|---|
| `chat-llm.test.ts` "falls back without calling the LLM when there is no evidence" | question "What is the weather in Paris?" → "Do you sell bicycles in Paris?"; assertion unchanged | weather is now stopped earlier as `out_of_scope` (covered by the new scope tests); the test still checks the exact `no_evidence` path |
| `SettingsTest::test_unchecked_checkbox_disables_widget` | unchanged; superseded for malformed values by `test_malformed_enabled_value_is_rejected_and_saved_value_kept` | requirement: invalid values must not silently replace a saved value |
| E2E "runs the nonce-protected connection test" | expected message now includes "the shared secret is accepted" | stronger: proves PHP signing and Node verification agree |
| `eval/dataset.json` `faq-14` | `answer` → `either`, both phone numbers forbidden | phone removed from the KB until the owner confirms it; not done to pass a threshold |

No test was deleted, no evaluation threshold was lowered, and `RETRIEVAL_MIN_SCORE` is unchanged.

## AI evaluation summary

Details and method: [evaluation.md](evaluation.md).

| Measure | Result | Status |
|---|---|---|
| Retrieval accuracy (expected page retrieved) | design 32/32, held-out 18/20 | measured offline |
| Answerable questions reaching the model with the right page | design 32/32, held-out 18/20; **0** false refusals by the new gate | measured offline |
| Unsupported-question refusal before the model | design 25/25 (in-sample), held-out 16/22 (73%) | measured offline |
| Grounded answer accuracy (≥ 90% target) | — | **blocked**: needs Gemini key + owner review |
| Unsupported claims in answers (target 0) | numbers/URLs/e-mails/phones are blocked structurally; prose claims unmeasured | **blocked** for prose |
| Source correctness (100% valid URLs) | enforced structurally (backend maps ids to KB URLs; WordPress and widget re-check hosts) | tested; live check in `eval:live` |
| Successful requests (≥ 95%), p95 latency (≤ 8 s) | — | **blocked** (mock latency is meaningless) |

`eval:live` was run once against the local mock only, to verify the runner (65/65 requests completed). Those numbers are not quality results.

## PRD acceptance criteria (§14)

| # | Criterion | Status |
|---|---|---|
| 1 | Plugin can be activated and deactivated | **Tested**: ZIP lifecycle on fresh WP 7.1.3 (SQLite) |
| 2 | Widget works on desktop and mobile | **Tested** in Chrome desktop + mobile emulation (not on physical devices) |
| 3 | Users can send messages and receive responses | **Tested** with mock provider |
| 4 | Answers are based on approved sources | **Tested** at retrieval, gate and claim-check level; real model **untested** |
| 5 | Source URLs valid and relevant | validity **tested**; relevance of the real model's citations **untested** |
| 6 | Unsupported questions → fallback | **Tested**: 25/25 design, 16/22 held-out stopped before the model; the remaining 6 depend on the real model (**untested**) |
| 7 | API key not exposed on the frontend | **Tested**: page HTML, inline config, settings page, logs |
| 8 | Validation and rate limiting on the chat endpoint | **Tested** (WordPress and backend, incl. trusted-proxy handling) |
| 9 | Internal endpoints authenticated | **Tested**: `/api/index` bearer token; signed health check |
| 10 | Timeouts and errors handled | **Tested** incl. stalled response bodies, oversized responses, invalid key, quota, outage |
| 11 | Server-side settings validation | **Tested** |
| 12 | Users without the capability cannot change settings | **Tested**: anonymous, subscriber, editor blocked |
| 13 | Private content not in the knowledge base | **Tested**: publish-only at build, index and load; `kb:validate` |
| 14 | Basic security and accessibility testing done | **Done** locally (phpcs security sniffs, negative tests, axe-core). Automated accessibility checks are not a WCAG compliance claim |
| 15 | No damage to the website during staging tests | **Not done**: no staging environment |

## Known limitations

- **No live LLM evaluation.** Tone, Indonesian translation, citation choice and refusal of the 6 held-out questions that still reach the model are unverified.
- **Gate rules are keyword-based** (English/Indonesian) and can be evaded by rephrasing; the claim check does not catch invented prose.
- **Single-turn answers.** Follow-ups like "tell me more about that" are answered without context and usually fall back.
- **Indonesian retrieval** relies on a hand-maintained term map; two held-out English paraphrases ("Where are you based?", "Which regions do you cover?") are retrieval misses.
- **Thin content.** The M&A page is empty; there is no FAQ, pricing, team or client information; no phone number is given until confirmed.
- **Backend state is in memory**: single instance only.
- **Flaky harness step:** in the last full `test-all.sh` run the headless-Chrome step failed with "Could not connect to Chrome" (the harness waits 10 s for Chrome to start); two immediate re-runs of `e2e/run.sh` passed 12/12 + 14/14. No product code is involved, but the startup wait should be made longer or configurable.
- **Not tested:** MySQL, LiteSpeed Cache/JS-combine interaction and its automatic purge, Apache/LiteSpeed rewrites, PHP 8.1–8.4 and 8.5.11 at runtime, real mobile devices and screen readers, Elementor popups on the real site.

## Open questions for Inpartner (PRD §17)

1. Which phone number is correct: footer `+62 859-3454-8202` or Contact page `+62 896 2831 0192`? Until answered, the bot gives none.
2. May the Contact URL change from the homepage to `https://inpartnerglobal.com/contact/`? (It stays the homepage until approved.)
3. Is `corporatesecretary@inpartner.id` the official contact address (different domain from the website)?
4. Remove placeholder text on the Contact page; add content to the Cross-Border M&A page.
5. English only, or English + Indonesian at launch?
6. Which pages should show the widget?
7. May the bot use the market-news posts? (Excluded today.)
8. Monthly Gemini budget → `DAILY_REQUEST_LIMIT`; are Google's API data-use terms acceptable?
9. Who approves knowledge-base changes, and how is that approval recorded?
10. Deployment target and whether more than one backend instance is ever required (decides whether a shared store is needed).
