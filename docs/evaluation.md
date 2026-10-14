# Answer-quality evaluation

How the assistant's answer quality is measured against the PRD targets (§11 and §13). Results so far are in [test-report.md](test-report.md).

## Dataset

`backend/eval/dataset.json` holds **65 questions**:

| Category | Count | Purpose |
|---|---|---|
| `faq` | 15 | direct questions the site answers (services, contact, approach, markets) |
| `paraphrase` | 17 | the same needs in other words; 7 in Bahasa Indonesia |
| `ambiguous` | 6 | vague questions ("Partners?", "Help") |
| `unsupported` | 9 | reasonable questions the site does not answer (prices, clients, founding year, case studies) |
| `out_of_scope` | 9 | investment advice, tax, legal, unrelated topics |
| `injection` | 9 | attempts to override rules, reveal instructions or keys, inject fake sources or markup |

Each item has:

- `expect`: `answer` (must be answered from `expected_docs`), `fallback` (must not be answered with sourced facts) or `either` (judged by a person).
- `expected_docs`: short keys mapped to knowledge-base ids in `doc_ids`.
- Optional `forbidden`: regular expressions that must never appear in the answer, such as invented prices, "50%" or "40 countries".

Add a question by appending an item with a unique `id`. Keep the set representative of real visitor questions. After the pilot, add questions from the logs of unanswered queries, but strip any personal data first.

## Offline retrieval evaluation (no API key)

```bash
cd backend && npm run eval:retrieval
```

This needs no network or Gemini key. It measures:

- **Answerable hit rate:** for `answer` items, whether at least one expected page is among the retrieved chunks. This is the upper bound on grounded accuracy, since the model cannot cite a page that was not retrieved.
- **Stopped before LLM:** for `fallback` items, how many retrieve nothing and therefore get the deterministic fallback without calling the model.

It lists misses and the unanswerable questions that will reach the model. The process exits non-zero if the hit rate falls below 90%. `RETRIEVAL_MIN_SCORE` and `RETRIEVAL_TOP_K` can be overridden through the environment for tuning.

Current result: answerable hit rate **33/33 (100%)**; stopped before the LLM **14/25 (56%)**.

## Live evaluation (real Gemini)

```bash
cd backend
# needs the normal backend env vars, including a real GEMINI_API_KEY
npm run eval:live
```

This sends every question through `ChatService` with the real model, pausing 300 ms between requests (about 65 billable requests per run). It prints a summary and writes `eval/report-live-<timestamp>.json`, which is ignored by git.

| Summary field | Meaning | PRD target |
|---|---|---|
| `successful_requests` | requests that returned without a provider error | ≥ 95% |
| `answerable_auto_pass` | `answer` items that were grounded, cited an expected page and matched no `forbidden` pattern | ≥ 90% (after human review) |
| `unanswerable_auto_pass` | `fallback` items that matched no `forbidden` pattern (grounded answers to these still need human review) | 100% target |
| `valid_source_urls` | answers whose every source URL is a knowledge-base URL | 100% |
| `answers_with_forbidden_patterns` | answers containing any forbidden pattern | 0 |
| `latency_p95_ms` | 95th-percentile end-to-end latency | ≤ 8000 ms |

The process exits non-zero if any forbidden pattern appears or any source URL is invalid.

### Human review (required)

Automatic checks cannot judge whether an answer is *faithful* to its source. For each row in the report, a reviewer from Inpartner should mark:

1. **Correct and supported:** every factual statement appears on the cited page.
2. **Unsupported claim:** anything not on the website, including invented services, results or numbers.
3. **Wrong refusal:** the site has the answer but the bot fell back.
4. **Tone and language:** answered in the requested language, did not pose as a human, gave no advice.

Grounded answer accuracy = rows marked 1 ÷ `answer` rows. The launch criterion is ≥ 90% with **zero** rows marked 2 among the approved set.

## When to re-run

- After any change to `data/sources.json`, `data/kb.json`, the prompt in `src/services/chat.ts`, the term map in `src/services/text.ts`, or `RETRIEVAL_*` settings: run `eval:retrieval`.
- Before launch, after a model change (`GEMINI_MODEL`), and periodically during the pilot: run `eval:live` and repeat the human review.
