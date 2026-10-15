/**
 * Checks that every knowledge-base document is actually published at the URL
 * it cites. A citation is a promise to the visitor that the linked page says
 * what the answer says; kb:validate cannot check that offline.
 *
 * For each document: fetch its URL (https, approved host, no redirects to
 * other hosts, 20 s timeout, 2 MiB cap), convert the page to text and report
 * how many of the document's lines (>= 25 characters) appear on the page,
 * compared case-, whitespace- and punctuation-insensitively.
 *
 * Exits 1 when a page cannot be fetched or a document's coverage is below
 * --min-coverage (default 0.8). Needs network access to the live site, so
 * it is not part of CI; run it before deploying a new kb.json and on staging.
 *
 * Usage: npm run kb:verify-sources [-- --kb=data/kb.json --min-coverage=0.8 --show-missing]
 */
import { readFile } from 'node:fs/promises';
import { htmlToText } from '../src/lib/html-to-text.js';
import { KnowledgeBaseStore } from '../src/services/knowledge-base.js';

const MAX_PAGE_BYTES = 2 * 1024 * 1024;
const MIN_LINE_CHARS = 25;

function arg(name: string, fallback: string): string {
  const match = process.argv.find((a) => a.startsWith(`--${name}=`));
  return match ? match.slice(name.length + 3) : fallback;
}

/** Lower-case letters and digits only, single-spaced: survives markup, bullets and punctuation differences. */
export function normalizeForMatch(text: string): string {
  return text
    .toLowerCase()
    .normalize('NFKC')
    .replace(/[^\p{L}\p{N}]+/gu, ' ')
    .trim();
}

/** Share of the document's substantial lines that occur in the page text. */
export function lineCoverage(documentText: string, pageText: string): { checked: number; found: number; missing: string[] } {
  const page = ` ${normalizeForMatch(pageText)} `;
  const lines = documentText
    .split('\n')
    .map((l) => l.trim())
    .filter((l) => l.length >= MIN_LINE_CHARS);
  const missing = lines.filter((l) => !page.includes(` ${normalizeForMatch(l)} `));
  return { checked: lines.length, found: lines.length - missing.length, missing };
}

async function fetchPage(url: string): Promise<{ status: number; text: string }> {
  const response = await fetch(url, {
    headers: { Accept: 'text/html', 'User-Agent': 'WPKnowledgeChat-SourceVerifier/0.2' },
    redirect: 'manual',
    signal: AbortSignal.timeout(20_000),
  });
  if (!response.ok || !response.body) {
    await response.body?.cancel().catch(() => undefined);
    return { status: response.status, text: '' };
  }
  const reader = response.body.getReader();
  const chunks: Uint8Array[] = [];
  let size = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    size += value.byteLength;
    if (size > MAX_PAGE_BYTES) {
      await reader.cancel();
      break;
    }
    chunks.push(value);
  }
  return { status: response.status, text: htmlToText(Buffer.concat(chunks).toString('utf8')) };
}

async function main(): Promise<void> {
  const kbPath = arg('kb', 'data/kb.json');
  const minCoverage = Number(arg('min-coverage', '0.8'));
  const showMissing = process.argv.includes('--show-missing');
  const hosts = (process.env.ALLOWED_SOURCE_HOSTS ?? 'inpartnerglobal.com,www.inpartnerglobal.com,inpartner.id,www.inpartner.id')
    .split(',')
    .map((h) => h.trim().toLowerCase())
    .filter(Boolean);

  const store = new KnowledgeBaseStore(hosts);
  const loaded = store.loadFrom(JSON.parse(await readFile(kbPath, 'utf8')));
  if (loaded.rejected.length > 0) throw new Error(`${loaded.rejected.length} document(s) in ${kbPath} are invalid; run kb:validate`);

  let failed = 0;
  for (const doc of store.all()) {
    let result: { status: number; text: string };
    try {
      result = await fetchPage(doc.url);
    } catch (err) {
      failed += 1;
      console.log(`FAIL ${doc.id}: fetch failed (${err instanceof Error ? err.name : 'error'}) ${doc.url}`);
      continue;
    }
    if (result.status !== 200) {
      failed += 1;
      console.log(`FAIL ${doc.id}: HTTP ${result.status} ${doc.url}`);
      continue;
    }
    const cov = lineCoverage(doc.text, result.text);
    const ratio = cov.checked === 0 ? 1 : cov.found / cov.checked;
    const ok = ratio >= minCoverage;
    if (!ok) failed += 1;
    console.log(`${ok ? 'OK  ' : 'FAIL'} ${doc.id}: ${cov.found}/${cov.checked} lines found on ${doc.url} (${(ratio * 100).toFixed(0)}%)`);
    if (showMissing) for (const line of cov.missing) console.log(`       missing: ${line.slice(0, 140)}`);
  }
  console.log(failed === 0 ? 'All cited pages contain their knowledge-base text.' : `${failed} document(s) are not supported by the page they cite.`);
  process.exit(failed === 0 ? 0 : 1);
}

// Run only as a script, not when imported by tests.
if (process.argv[1]?.endsWith('verify-sources.js')) {
  main().catch((err: unknown) => {
    console.error(`kb:verify-sources failed: ${err instanceof Error ? err.message : String(err)}`);
    process.exit(2);
  });
}
