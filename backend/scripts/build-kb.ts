/**
 * Builds data/kb.json from the approved source list in data/sources.json.
 *
 * Fetches ONLY the WordPress page IDs listed there, through the site's public
 * REST API, and only keeps pages whose status is "publish". The output is a
 * reviewable snapshot: inspect the diff before deploying or pushing it.
 *
 * A required page that cannot be fetched, is not published or has no body
 * text fails the whole build, so a network error never silently drops a
 * page from the knowledge base. Pages marked "optional" are skipped with a
 * warning. The result is audited (see src/services/kb-audit.ts) before it
 * is written atomically; the previous file is left untouched on failure.
 *
 * Usage: npm run kb:build [-- --sources=data/sources.json --out=data/kb.json]
 */
import { readFile } from 'node:fs/promises';
import { htmlToText } from '../src/lib/html-to-text.js';
import { auditKnowledgeBase, excludePatternsFor, MIN_DOCUMENT_CHARS, parseSourcesFile } from '../src/services/kb-audit.js';
import { KnowledgeBaseStore, validateDocument, writeKnowledgeBaseFile, type KnowledgeDocument } from '../src/services/knowledge-base.js';

function arg(name: string, fallback: string): string {
  const match = process.argv.find((a) => a.startsWith(`--${name}=`));
  return match ? match.slice(name.length + 3) : fallback;
}

async function readJsonIfExists(path: string): Promise<unknown> {
  try {
    return JSON.parse(await readFile(path, 'utf8'));
  } catch {
    return undefined;
  }
}

async function main(): Promise<void> {
  const sourcesPath = arg('sources', 'data/sources.json');
  const outPath = arg('out', 'data/kb.json');
  const sources = parseSourcesFile(JSON.parse(await readFile(sourcesPath, 'utf8')));

  const site = new URL(sources.site);
  const allowedHosts = [site.hostname, site.hostname.startsWith('www.') ? site.hostname.slice(4) : `www.${site.hostname}`];

  const documents: KnowledgeDocument[] = [];
  const failures: string[] = [];
  for (const entry of sources.documents) {
    const skip = (reason: string) => {
      if (entry.optional) console.warn(`SKIP ${entry.id} (optional): ${reason}`);
      else failures.push(`${entry.id}: ${reason}`);
    };

    const endpoint = new URL(`/wp-json/wp/v2/pages/${entry.wp_id}`, site);
    endpoint.searchParams.set('_fields', 'id,link,title,content,status,modified_gmt');

    let page: { id: number; link: string; status: string; modified_gmt: string; title: { rendered: string }; content: { rendered: string } };
    try {
      const response = await fetch(endpoint, {
        headers: { Accept: 'application/json', 'User-Agent': 'WPKnowledgeChat-KB-Builder/0.1' },
        redirect: 'error',
        signal: AbortSignal.timeout(20_000),
      });
      if (!response.ok) {
        skip(`HTTP ${response.status}`);
        continue;
      }
      page = (await response.json()) as typeof page;
    } catch (err) {
      skip(`fetch failed (${err instanceof Error ? err.name : 'error'})`);
      continue;
    }
    if (page.id !== entry.wp_id) {
      skip(`response is for page ${String(page.id)}, not ${entry.wp_id}`);
      continue;
    }
    if (page.status !== 'publish') {
      skip(`status "${page.status}" is not publish`);
      continue;
    }

    const text = htmlToText(page.content.rendered, { excludePatterns: excludePatternsFor(sources, entry) });
    if (text.length < MIN_DOCUMENT_CHARS) {
      skip(`page has no meaningful body text (${text.length} chars)`);
      continue;
    }

    try {
      const doc = validateDocument(
        {
          id: entry.id,
          title: htmlToText(page.title.rendered) || entry.id,
          url: page.link,
          text,
          category: entry.category,
          status: page.status,
          updated_at: `${page.modified_gmt}Z`,
        },
        allowedHosts,
      );
      documents.push(doc);
      console.log(`OK   ${doc.id}: ${doc.text.length} chars -> ${doc.url}`);
    } catch (err) {
      skip(`invalid document (${err instanceof Error ? err.message : 'error'})`);
    }
  }

  if (failures.length > 0) {
    throw new Error(`required pages could not be built; ${outPath} was not changed:\n  ${failures.join('\n  ')}`);
  }

  const store = new KnowledgeBaseStore(allowedHosts);
  store.apply({ upserts: documents, deleteIds: [], replaceAll: true });
  const file = store.toFile();

  const audit = auditKnowledgeBase(file, sources, allowedHosts, await readJsonIfExists(outPath));
  for (const warning of audit.warnings) console.warn(`WARN ${warning}`);
  if (audit.errors.length > 0) throw new Error(`audit failed; ${outPath} was not changed:\n  ${audit.errors.join('\n  ')}`);

  const { durable } = await writeKnowledgeBaseFile(outPath, file);
  if (!durable) throw new Error(`${outPath} is on a read-only file system; the snapshot was only written to the temporary directory`);
  console.log(`Wrote ${documents.length} documents (${store.chunks().length} chunks) to ${outPath}`);
}

main().catch((err: unknown) => {
  console.error(`kb:build failed: ${err instanceof Error ? err.message : String(err)}`);
  process.exit(1);
});
