/**
 * Evaluation runner.
 *
 *   --mode=retrieval  Deterministic, offline. Measures whether the retriever
 *                     surfaces an expected document for answerable questions
 *                     and whether unanswerable ones are stopped before the LLM.
 *   --mode=live       Calls the real Gemini API through ChatService (needs
 *                     GEMINI_API_KEY and the other backend env vars). Writes a
 *                     per-question report for human review of grounded accuracy.
 *
 * Usage: npm run eval:retrieval | npm run eval:live
 */
import { readFile, writeFile } from 'node:fs/promises';
import { loadConfig } from '../src/config/env.js';
import { createLogger } from '../src/lib/logger.js';
import { ChatService, type Language } from '../src/services/chat.js';
import { KnowledgeBaseStore, readKnowledgeBaseFile } from '../src/services/knowledge-base.js';
import { GeminiClient, LlmError } from '../src/services/llm.js';
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

async function retrievalEval(dataset: Dataset): Promise<boolean> {
  const topK = Number(process.env.RETRIEVAL_TOP_K ?? 4);
  const minScore = Number(process.env.RETRIEVAL_MIN_SCORE ?? 1.0);
  const store = await loadStore(process.env.KB_PATH ?? 'data/kb.json', ['inpartnerglobal.com', 'www.inpartnerglobal.com']);
  const retriever = new Bm25Retriever(store.chunks());

  let answerTotal = 0;
  let answerHits = 0;
  let fallbackTotal = 0;
  let fallbackBlocked = 0;
  const misses: string[] = [];
  const needsLlm: string[] = [];

  for (const item of dataset.items) {
    const results = retriever.search(item.question, topK, minScore);
    const docs = [...new Set(results.map((r) => r.chunk.docId))];
    const expected = item.expected_docs.map((k) => dataset.doc_ids[k]);
    if (item.expect === 'answer') {
      answerTotal += 1;
      if (docs.some((d) => expected.includes(d))) answerHits += 1;
      else misses.push(`${item.id} "${item.question}" -> [${docs.join(', ')}]`);
    } else if (item.expect === 'fallback') {
      fallbackTotal += 1;
      if (results.length === 0) fallbackBlocked += 1;
      else needsLlm.push(`${item.id} "${item.question}" -> [${docs.join(', ')}] top=${results[0]?.score.toFixed(2)}`);
    }
  }

  console.log(`Retrieval evaluation (topK=${topK}, minScore=${minScore}, ${dataset.items.length} questions)`);
  console.log(`  Answerable: expected document retrieved  ${answerHits}/${answerTotal} (${pct(answerHits, answerTotal)})`);
  console.log(`  Unanswerable: stopped before LLM         ${fallbackBlocked}/${fallbackTotal} (${pct(fallbackBlocked, fallbackTotal)})`);
  if (misses.length) console.log(`  Misses:\n    ${misses.join('\n    ')}`);
  if (needsLlm.length) console.log(`  Unanswerable questions that reach the LLM (model must decline):\n    ${needsLlm.join('\n    ')}`);
  return answerTotal > 0 && answerHits / answerTotal >= 0.9;
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

  const rows: unknown[] = [];
  let ok = 0, failedRequests = 0, answerTotal = 0, answerPass = 0, fallbackTotal = 0, fallbackPass = 0;
  let citing = 0, validCitations = 0, forbiddenHits = 0;
  const latencies: number[] = [];

  for (const item of dataset.items) {
    const started = Date.now();
    try {
      const result = await chat.answer(item.question, item.language);
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
        pass = result.grounded && sourceUrls.some((u) => expected.includes(u)) && forbidden.length === 0;
        if (pass) answerPass += 1;
      } else if (item.expect === 'fallback') {
        fallbackTotal += 1;
        pass = forbidden.length === 0 && (!result.grounded || result.sources.length > 0);
        if (pass) fallbackPass += 1;
      }
      rows.push({ ...item, result, outcome: result.outcome, forbidden_matched: forbidden, auto_pass: pass, latency_ms: Date.now() - started });
    } catch (err) {
      failedRequests += 1;
      rows.push({ ...item, error: err instanceof LlmError ? err.kind : 'error' });
    }
    await new Promise((r) => setTimeout(r, 300));
  }

  latencies.sort((a, b) => a - b);
  const p95 = latencies[Math.max(0, Math.ceil(latencies.length * 0.95) - 1)] ?? 0;
  const summary = {
    model: config.geminiModel,
    questions: dataset.items.length,
    successful_requests: `${ok}/${dataset.items.length} (${pct(ok, dataset.items.length)})`,
    answerable_auto_pass: `${answerPass}/${answerTotal} (${pct(answerPass, answerTotal)})`,
    unanswerable_auto_pass: `${fallbackPass}/${fallbackTotal} (${pct(fallbackPass, fallbackTotal)})`,
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
  const dataset = JSON.parse(await readFile('eval/dataset.json', 'utf8')) as Dataset;
  const passed = mode === 'live' ? await liveEval(dataset) : await retrievalEval(dataset);
  process.exit(passed ? 0 : 1);
}

main().catch((err: unknown) => {
  console.error(`eval failed: ${err instanceof Error ? err.message : String(err)}`);
  process.exit(2);
});
