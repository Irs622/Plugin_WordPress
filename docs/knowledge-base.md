# Knowledge base

## What is indexed (verified 2026-10-14)

Pages were discovered through the site's public WordPress REST API (`/wp-json/wp/v2/pages`, `/posts`). Only published content is public there.

| Doc id | Page | Status in KB |
|---|---|---|
| `page-196-home` | https://inpartnerglobal.com/ | indexed (news teasers and empty "Our Clients" heading removed) |
| `page-223-about` | https://inpartnerglobal.com/about-us/ | indexed |
| `page-628-cross-border-market-entry` | https://inpartnerglobal.com/cross-border-market-entry-advisor/ | indexed |
| `page-918-distribution-partner-sourcing` | https://inpartnerglobal.com/distribution-partner-sourcing-advisory/ | indexed |
| `page-445-contact` | https://inpartnerglobal.com/contact/ | indexed, **2 placeholder lines excluded** |
| `page-1052-cross-border-ma` | https://inpartnerglobal.com/cross-border-ma-advisory/ | **skipped: page body is empty**; indexed automatically once it has content |
| post 318, post 315 | stock / currency market commentary | **excluded**: could read as investment advice; needs explicit approval |

Snapshot: `backend/data/kb.json` (5 documents, 10 chunks). Allowlist and exclusion rules: `backend/data/sources.json`.

## Content issues for the Inpartner team

1. **Contact page placeholder text.** "(406) 555-0120" is a fictional 555 number and "Ready to take control of your financial future? … Fill out the form below" reads like theme demo copy. Both are excluded from the KB; please remove them from the page.
2. **Two different phone numbers.** The footer shows `+62 859-3454-8202` and the Contact page shows `+62 896 2831 0192`. The bot only knows the Contact page number. Please confirm which is correct.
3. **Address wording differs** between the footer ("Pakuwon Tower 10th Floor Unit J, Jl. Casablanca Raya Kav. 88, South Jakarta") and the Contact page ("Pakuwon Tower Lantai 10, … Tebet, Jakarta Selatan").
4. **Cross-Border M&A Advisory page has no body content.** The bot cannot describe this service beyond the homepage's "Cross-Border Transactions" card.
5. Minor typos on the live site: "Start a Convertation", "Lear More", "Build for Companies".
6. No FAQ page, pricing, team or client information is published, so the bot will (correctly) decline those questions.

## How the build works

`npm run kb:build` (in `backend/`):

1. Reads `data/sources.json`: the **only** pages fetched, by numeric WordPress ID.
2. Fetches each via `GET /wp-json/wp/v2/pages/<id>` (public, read-only; redirects refused; 20 s timeout).
3. Skips anything whose `status` is not `publish`.
4. Converts HTML to text: drops scripts/styles/nav/header/footer/forms, decodes entities, removes duplicated Elementor blocks and CTA boilerplate (`global_exclude_patterns`) plus page-specific `exclude_patterns`.
5. Skips pages with < 40 characters of text.
6. Validates each document (id, title, https URL on an approved host, category, status, timestamp) and writes `data/kb.json` atomically.

Chunks (~700 characters, line-aligned, prefixed with the page title) are computed at load time.

## Updating the knowledge base

```bash
cd backend
npm run kb:build                  # refresh from the live public pages
git diff data/kb.json             # REVIEW: confirm no unwanted/incorrect text
npm run eval:retrieval            # must stay ≥ 90% for answerable questions
# then either redeploy the backend with the new kb.json, or push it:
BACKEND_URL=https://chat-api.example.com INDEX_API_TOKEN=… npm run kb:push
```

- **Add a page:** add `{ "id": "page-<wpid>-<slug>", "wp_id": <id>, "category": "service" }` to `sources.json` after the content owner approves it.
- **Remove a page:** delete it from `sources.json` and rebuild (the push uses `replace_all`, so it disappears from the index).
- **Exclude a sentence:** add a case-insensitive regex to that page's `exclude_patterns`.
- **Approve the posts:** only after deciding the bot may discuss market news. Even then, the system prompt forbids investment advice.

## Retrieval tuning

- `RETRIEVAL_MIN_SCORE` (default 1.0): lower means more questions reach the LLM; higher means more deterministic fallbacks. Measured on the 65-question set:

  | threshold | answerable hit rate | unanswerable stopped before LLM |
  |---|---|---|
  | 0.8 | 100% | 44% |
  | **1.0** | **100%** | **56%** |
  | 1.2 | 97% | 64% |
  | 1.5 | 97% | 64% |

- Indonesian support relies on the term map in `src/services/text.ts`. Add terms when the evaluation shows misses.
- Move to embeddings only if lexical retrieval falls below target as content grows (for example, many articles). The interface (`Bm25Retriever.search`) can be swapped.
