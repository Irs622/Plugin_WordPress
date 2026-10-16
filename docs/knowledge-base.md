# Knowledge base

## Status 2026-10-16: restored to the site snapshot

The knowledge base is the 5-page snapshot from commit `a895e24` (home, about-us, contact, cross-border market entry, distribution partner sourcing). `npm run kb:verify-sources` passes 5/5 against the live site. The Cross-Border M&A page is not included (optional, no usable text on the site).

KB v2.0 (commit `4b9e6b2`) was removed because its text is not on the pages it cites. To bring those facts back, **publish them on the site first**, then add the page to `data/sources.json`, run `npm run kb:build`, then `kb:verify-sources` and `kb:validate`:

| Fact | Where it was | Needed on the site |
|---|---|---|
| Legal name PT Inpartner Optima Integra | v2.0 home/about | live Contact page already shows it; add it to the page that is cited |
| 5-stage consulting approach | v2.0 about | publish on About |
| Track record "90+ projects, 70+ clients, 10+ foreign-related" | v2.0 FAQ | publish, with an owner-approved wording |
| FAQ and chatbot boundaries | v2.0 FAQ (`/faq/` is 404) | create `/faq/` |
| Phone number | v2.0 contact | owner must confirm the correct number; the Contact page shows more than one |

## What is indexed (verified 2026-10-14)

Pages were discovered through the site's public WordPress REST API (`/wp-json/wp/v2/pages`, `/posts`). Only published content is public there.

| Doc id | Page | Status in KB |
|---|---|---|
| `page-196-home` | https://inpartnerglobal.com/ | indexed (news teasers and empty "Our Clients" heading removed) |
| `page-223-about` | https://inpartnerglobal.com/about-us/ | indexed |
| `page-628-cross-border-market-entry` | https://inpartnerglobal.com/cross-border-market-entry-advisor/ | indexed |
| `page-918-distribution-partner-sourcing` | https://inpartnerglobal.com/distribution-partner-sourcing-advisory/ | indexed |
| `page-445-contact` | https://inpartnerglobal.com/contact/ | indexed; **2 placeholder lines, the disputed phone number and its "Call Us" label excluded** |
| `page-1052-cross-border-ma` | https://inpartnerglobal.com/cross-border-ma-advisory/ | marked `optional`; **skipped: page body is empty**; indexed automatically once it has content |
| post 318, post 315 | stock / currency market commentary | **excluded**: could read as investment advice; needs explicit approval |

Snapshot: `backend/data/kb.json` (5 documents, 10 chunks). Allowlist and exclusion rules: `backend/data/sources.json`.

## Content issues for the Inpartner team

1. **Contact page placeholder text.** "(406) 555-0120" is a fictional 555 number and "Ready to take control of your financial future? … Fill out the form below" reads like theme demo copy. Both are excluded from the KB; please remove them from the page.
2. **Two different phone numbers.** The footer shows `+62 859-3454-8202` and the Contact page shows `+62 896 2831 0192`. Until Inpartner confirms the authoritative number, **every line that is only a phone number is excluded** (`global_exclude_patterns` in `sources.json`, see `$phone_policy`), so the bot gives no phone number; the claim check also blocks any phone number the model might write. After confirmation, remove the pattern and rebuild.
3. **Address wording differs** between the footer ("Pakuwon Tower 10th Floor Unit J, Jl. Casablanca Raya Kav. 88, South Jakarta") and the Contact page ("Pakuwon Tower Lantai 10, … Tebet, Jakarta Selatan").
4. **Cross-Border M&A Advisory page has no body content.** The bot cannot describe this service beyond the homepage's "Cross-Border Transactions" card.
5. Minor typos on the live site: "Start a Convertation", "Lear More", "Build for Companies".
6. No FAQ page, pricing, team or client information is published, so the bot will (correctly) decline those questions.
7. **Contact e-mail domain** is `inpartner.id`, not the website domain. Please confirm it is the official address.

## How the build works

`npm run kb:build` (in `backend/`):

1. Reads `data/sources.json`: the **only** pages fetched, by numeric WordPress ID.
2. Fetches each via `GET /wp-json/wp/v2/pages/<id>` (public, read-only; redirects refused; 20 s timeout).
3. Refuses anything whose `status` is not `publish`, or whose response is for a different page id.
4. Converts HTML to text: drops scripts/styles/nav/header/footer/forms, decodes entities, removes duplicated Elementor blocks and CTA boilerplate (`global_exclude_patterns`) plus page-specific `exclude_patterns`.
5. Refuses pages with < 40 characters of text.
6. Validates each document (id, title, https URL on an approved host, category, status, timestamp).
7. **A required page that fails steps 2–6 fails the whole build and leaves the old `kb.json` untouched.** Pages marked `"optional": true` are skipped with a warning instead.
8. Audits the result (`src/services/kb-audit.ts`): only approved ids, none missing, no duplicates, categories match, no excluded lines, warns when a page lost more than half its text since the previous snapshot. Then writes `data/kb.json` atomically.

`npm run kb:validate` runs the same audit offline on the committed snapshot (also part of `scripts/test-all.sh`). `sources.json` itself is validated too: unique ids and WordPress ids, valid categories and regular expressions, https site.

Chunks (~700 characters, line-aligned, prefixed with the page title) are computed at load time.

## Updating the knowledge base

```bash
cd backend
npm run kb:build                  # refresh from the live public pages (read-only GETs)
git diff data/kb.json             # REVIEW: confirm no unwanted/incorrect text
npm run kb:validate               # offline audit against sources.json
npm run eval:retrieval            # must stay ≥ 90% for answerable questions
node dist/eval/run-eval.js --mode=retrieval --dataset=eval/holdout.json
# then either redeploy the backend with the new kb.json, or push it:
BACKEND_URL=https://chat-api.example.com INDEX_API_TOKEN=… npm run kb:push
```

- **Add a page:** add `{ "id": "page-<wpid>-<slug>", "wp_id": <id>, "category": "service" }` to `sources.json` after the content owner approves it.
- **Remove a page:** delete it from `sources.json` and rebuild (the push uses `replace_all`, so it disappears from the index).
- **Exclude a sentence:** add a case-insensitive regex to that page's `exclude_patterns`.
- **Approve the posts:** only after deciding the bot may discuss market news. Even then, the system prompt forbids investment advice.

## Retrieval tuning

- `RETRIEVAL_MIN_SCORE` (default 1.0, unchanged): lower means more questions reach the LLM; higher means more deterministic fallbacks. Raising it was **not** used to improve refusals: at 1.2–1.5 it already loses an answerable question while still letting keyword-matching unanswerable questions through. The scope and answer-type checks ([evaluation.md](evaluation.md)) handle those instead. Measured on the 65-question set **before** those checks existed (retrieval only):

  | threshold | answerable hit rate | unanswerable stopped before LLM |
  |---|---|---|
  | 0.8 | 100% | 44% |
  | **1.0** | **100%** | **56%** |
  | 1.2 | 97% | 64% |
  | 1.5 | 97% | 64% |

- Indonesian and Korean support rely on the term maps in `src/services/text.ts` (`QUERY_EXPANSIONS`, `KOREAN_EXPANSIONS`). Korean terms are matched as substrings of the question, so particles attached to a word (서비스는, 시장에) do not matter. Keep Korean keys at least two syllables to avoid accidental matches. Add terms when the evaluation shows misses.
- Move to embeddings only if lexical retrieval falls below target as content grows (for example, many articles). The interface (`Bm25Retriever.search`) can be swapped.
