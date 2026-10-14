/**
 * Evaluation runner.
 *
 *   --mode=retrieval  Deterministic, offline. Runs every question through
 *                     ChatService with a probe in place of the model and
 *                     reports (1) whether the retriever surfaces an expected
 *                     document, (2) whether answerable questions actually reach
 *                     the model with that document (the scope/evidence gate can
 *                     refuse them), and (3) how many unanswerable questions are
 *                     stopped before the model, and why.
 *   --mode=live       Calls the real Gemini API through ChatService (needs
 *                     GEMINI_API_KEY and the other backend env vars). Writes a
 *                     per-question report for human review of grounded accuracy.
 *   --dataset=PATH    Question set (default eval/dataset.json; eval/holdout.json
 *                     is the held-out set).
 *
 * Usage: npm run eval:retrieval [-- --dataset=eval/holdout.json] | npm run eval:live
 */
import { readFile, writeFile } from 'node:fs/promises';
import { loadConfig } from '../src/config/env.js';
import { createLogger } from '../src/lib/logger.js';
import { ChatService, type ChatOutcome, type Language } from '../src/services/chat.js';
import { KnowledgeBaseStore, readKnowledgeBaseFile } from '../src/services/knowledge-base.js';
import { GeminiClient, LlmError, type LlmRequest } from '../src/services/llm.js';
import { Bm25Retriever } from '../src/services/retrieval.js';

interface Item {
  id: string;
  category: string;
  language: Language;
  question: string;
  expect: 'answer' | 'fallback' | 'either';
  expected_docs: string[];
  forbidden?: string[];
}

interface Dataset {
  doc_ids: Record<string, string>;
  items: Item[];
}

const pct = (n: number, d: number) => (d === 0 ? 'n/a' : `${((100 * n) / d).toFixed(1)}%`);

async function loadStore(kbPath: string, hosts: string[]): Promise<KnowledgeBaseStore> {
  const store = new KnowledgeBaseStore(hosts);
  store.loadFrom(await readKnowledgeBaseFile(kbPath));
  return store;
}

/** Stands in for the model: records the request and cites S1 so the pipeline reaches its end. */
class ProbeLlm {
  last: LlmRequest | null = null;
  async generate(request: LlmRequest): Promise<string> {
    this.last = request;
    return JSON.stringify({ answer: 'probe', source_ids: ['S1'], grounded: true });
  }
}

/** Ids of the documents included in a prompt, read back from the probe request. */
function promptDocIds(request: LlmRequest | null, titleToId: Map<string, string>): string[] {
  if (!request) return [];
  const titles = [...request.userContent.matchAll(/<source id="S\d+" title="([^"]*)">/g)].map((m) => m[1] ?? '');
  return [...new Set(titles.map((t) => titleToId.get(t) ?? '').filter(Boolean))];
}

async function retrievalEval(dataset: Dataset): Promise<boolean> {
  const topK = Number(process.env.RETRIEVAL_TOP_K ?? 4);
  const minScore = Number(process.env.RETRIEVAL_MIN_SCORE ?? 1.0);
  const store = await loadStore(process.env.KB_PATH ?? 'data/kb.json', ['inpartnerglobal.com', 'www.inpartnerglobal.com']);
  const retriever = new Bm25Retriever(store.chunks());
  const probe = new ProbeLlm();
  const chat = new ChatService({ store, retriever, llm: probe, logger: createLogger('error'), topK, minScore, secrets: [] });
  const titleToId = new Map(store.all().map((d) => [d.title.replace(/"/g, "'"), d.id]));

  let answerTotal = 0, retrievalHits = 0, reachedWithEvidence = 0, fallbackTotal = 0, fallbackStopped = 0;
  const retrievalMisses: string[] = [];
  const falseRefusals: string[] = [];
  const reachesModel: string[] = [];
  const stoppedBy: Record<string, number> = {};
  const eitherRows: string[] = [];

  for (const item of dataset.items) {
    const docs = [...new Set(retriever.search(item.question, topK, minScore).map((r) => r.chunk.docId))];
    const expected = item.expected_docs.map((k) => dataset.doc_ids[k]);
    probe.last = null;
    const result = await chat.answer(item.question, item.language);
    const reached = probe.last !== null;
    const why = `${result.outcome}${result.reason ? `:${result.reason}` : ''}`;

    if (item.expect === 'answer') {
      answerTotal += 1;
      if (docs.some((d) => expected.includes(d))) retrievalHits += 1;
      else retrievalMisses.push(`${item.id} "${item.question}" -> [${docs.join(', ')}]`);
      if (reached && promptDocIds(probe.last, titleToId).some((d) => expected.includes(d))) reachedWithEvidence += 1;
      else falseRefusals.push(`${item.id} "${item.question}" -> ${reached ? 'reached the model without an expected page' : why}`);
    } else if (item.expect === 'fallback') {
      fallbackTotal += 1;
      if (reached) reachesModel.push(`${item.id} "${item.question}" -> [${docs.join(', ')}]`);
      else {
        fallbackStopped += 1;
        stoppedBy[why] = (stoppedBy[why] ?? 0) + 1;
      }
    } else {
      eitherRows.push(`${item.id} "${item.question}" -> ${reached ? 'reaches model' : why}`);
    }
  }

  console.log(`Offline evaluation (topK=${topK}, minScore=${minScore}, ${dataset.items.length} questions)`);
  console.log(`  Answerable: expected page retrieved              ${retrievalHits}/${answerTotal} (${pct(retrievalHits, answerTotal)})`);
  console.log(`  Answerable: reaches the model with expected page ${reachedWithEvidence}/${answerTotal} (${pct(reachedWithEvidence, answerTotal)})`);
  console.log(`  Unanswerable: stopped before the model           ${fallbackStopped}/${fallbackTotal} (${pct(fallbackStopped, fallbackTotal)})`);
  console.log(`    by: ${Object.entries(stoppedBy).sort().map(([k, v]) => `${k}=${v}`).join(', ') || 'none'}`);
  if (retrievalMisses.length) console.log(`  Retrieval misses:\n    ${retrievalMisses.join('\n    ')}`);
  if (falseRefusals.length) console.log(`  Answerable questions that do not reach the model with an expected page:\n    ${falseRefusals.join('\n    ')}`);
  if (reachesModel.length) console.log(`  Unanswerable questions that reach the model (it must decline; answers are claim-checked):\n    ${reachesModel.join('\n    ')}`);
  if (eitherRows.length) console.log(`  Ambiguous ("either") questions:\n    ${eitherRows.join('\n    ')}`);
  return answerTotal > 0 && reachedWithEvidence / answerTotal >= 0.9;
}

async function liveEval(dataset: Dataset): Promise<boolean> {
  const config = loadConfig();
  const store = await loadStore(config.kbPath, config.allowedSourceHosts);
  const logger = createLogger('warn');
  const chat = new ChatService({
    store,
    retriever: new Bm25Retriever(store.chunks()),
    llm: new GeminiClient({
      apiKey: config.geminiApiKey,
      model: config.geminiModel,
      baseUrl: config.geminiApiBaseUrl,
      timeoutMs: config.geminiTimeoutMs,
      maxOutputTokens: config.geminiMaxOutputTokens,
    }),
    logger,
    topK: config.retrievalTopK,
    minScore: config.retrievalMinScore,
    secrets: [config.geminiApiKey, config.chatSharedSecret, config.indexApiToken],
  });
  const kbUrls = new Set(store.all().map((d) => d.url));

  const retriever = new Bm25Retriever(store.chunks());
  const rows: unknown[] = [];
  let ok = 0, failedRequests = 0, answerTotal = 0, answerPass = 0, answerRetrieved = 0, fallbackTotal = 0, fallbackPass = 0, fallbackNeedsReview = 0;
  let citing = 0, validCitations = 0, forbiddenHits = 0;
  const latencies: number[] = [];
  const outcomes: Partial<Record<ChatOutcome | 'error', number>> = {};

  for (const item of dataset.items) {
    const started = Date.now();
    try {
      const result = await chat.answer(item.question, item.language);
      outcomes[result.outcome] = (outcomes[result.outcome] ?? 0) + 1;
      latencies.push(Date.now() - started);
      ok += 1;
      const expected = item.expected_docs.map((k) => store.get(dataset.doc_ids[k] ?? '')?.url).filter(Boolean);
      const sourceUrls = result.sources.map((s) => s.url);
      if (sourceUrls.length) {
        citing += 1;
        if (sourceUrls.every((u) => kbUrls.has(u))) validCitations += 1;
      }
      const forbidden = (item.forbidden ?? []).filter((p) => new RegExp(p, 'i').test(result.answer));
      if (forbidden.length) forbiddenHits += 1;
      let pass: boolean | null = null;
      if (item.expect === 'answer') {
        answerTotal += 1;
        const retrievedUrls = retriever.search(item.question, config.retrievalTopK, config.retrievalMinScore).map((r) => r.chunk.url);
        if (retrievedUrls.some((u) => expected.includes(u))) answerRetrieved += 1;
        pass = result.grounded && sourceUrls.some((u) => expected.includes(u)) && forbidden.length === 0;
        if (pass) answerPass += 1;
      } else if (item.expect === 'fallback') {
        fallbackTotal += 1;
        // Only a refusal passes automatically; a grounded answer to an unanswerable question needs human review.
        pass = !result.grounded && forbidden.length === 0;
        if (pass) fallbackPass += 1;
        else if (forbidden.length === 0) fallbackNeedsReview += 1;
      }
      rows.push({ ...item, result, outcome: result.outcome, forbidden_matched: forbidden, auto_pass: pass, latency_ms: Date.now() - started });
    } catch (err) {
      failedRequests += 1;
      outcomes.error = (outcomes.error ?? 0) + 1;
      rows.push({ ...item, error: err instanceof LlmError ? err.kind : 'error', latency_ms: Date.now() - started });
    }
    await new Promise((r) => setTimeout(r, 300));
  }

  latencies.sort((a, b) => a - b);
  const p95 = latencies[Math.max(0, Math.ceil(latencies.length * 0.95) - 1)] ?? 0;
  const summary = {
    model: config.geminiModel,
    questions: dataset.items.length,
    successful_requests: `${ok}/${dataset.items.length} (${pct(ok, dataset.items.length)})`,
    request_failure_rate: pct(failedRequests, dataset.items.length),
    answerable_retrieval_hit: `${answerRetrieved}/${answerTotal} (${pct(answerRetrieved, answerTotal)})`,
    answerable_auto_pass: `${answerPass}/${answerTotal} (${pct(answerPass, answerTotal)})`,
    unanswerable_refused: `${fallbackPass}/${fallbackTotal} (${pct(fallbackPass, fallbackTotal)})`,
    unanswerable_answered_needs_review: fallbackNeedsReview,
    outcomes,
    valid_source_urls: `${validCitations}/${citing} (${pct(validCitations, citing)})`,
    answers_with_forbidden_patterns: forbiddenHits,
    latency_p95_ms: p95,
    failed_requests: failedRequests,
  };
  const out = `eval/report-live-${new Date().toISOString().replace(/[:.]/g, '-')}.json`;
  await writeFile(out, JSON.stringify({ summary, rows }, null, 2));
  console.log(JSON.stringify(summary, null, 2));
  console.log(`Per-question results (review manually for unsupported claims): ${out}`);
  return forbiddenHits === 0 && validCitations === citing;
}

async function main(): Promise<void> {
  const mode = process.argv.find((a) => a.startsWith('--mode='))?.slice(7) ?? 'retrieval';
  const datasetPath = process.argv.find((a) => a.startsWith('--dataset='))?.slice(10) ?? 'eval/dataset.json';
  const dataset = JSON.parse(await readFile(datasetPath, 'utf8')) as Dataset;
  const passed = mode === 'live' ? await liveEval(dataset) : await retrievalEval(dataset);
  process.exit(passed ? 0 : 1);
}

main().catch((err: unknown) => {
  console.error(`eval failed: ${err instanceof Error ? err.message : String(err)}`);
  process.exit(2);
});
