/**
 * Builds data/kb.json from the approved source list in data/sources.json.
 *
 * Fetches ONLY the WordPress page IDs listed there, through the site's public
 * REST API, and only keeps pages whose status is "publish". The output is a
 * reviewable snapshot: inspect the diff before deploying or pushing it.
 *
 * Usage: npm run kb:build [-- --sources=data/sources.json --out=data/kb.json]
 */
import { readFile } from 'node:fs/promises';
import { htmlToText } from '../src/lib/html-to-text.js';
import {
  KnowledgeBaseStore,
  validateDocument,
  writeKnowledgeBaseFile,
  type KnowledgeDocument,
} from '../src/services/knowledge-base.js';

interface SourceEntry {
  id: string;
  wp_id: number;
  category: string;
  exclude_patterns?: string[];
}

interface SourcesFile {
  site: string;
  global_exclude_patterns: string[];
  documents: SourceEntry[];
}

function arg(name: string, fallback: string): string {
  const match = process.argv.find((a) => a.startsWith(`--${name}=`));
  return match ? match.slice(name.length + 3) : fallback;
}

async function main(): Promise<void> {
  const sourcesPath = arg('sources', 'data/sources.json');
  const outPath = arg('out', 'data/kb.json');
  const sources = JSON.parse(await readFile(sourcesPath, 'utf8')) as SourcesFile;

  const site = new URL(sources.site);
  if (site.protocol !== 'https:') throw new Error('sources.site must be https');
  const allowedHosts = [site.hostname, site.hostname.startsWith('www.') ? site.hostname.slice(4) : `www.${site.hostname}`];
  const globalPatterns = sources.global_exclude_patterns.map((p) => new RegExp(p, 'i'));

  const documents: KnowledgeDocument[] = [];
  for (const entry of sources.documents) {
    if (!Number.isInteger(entry.wp_id) || entry.wp_id <= 0) throw new Error(`Invalid wp_id for ${entry.id}`);
    const endpoint = new URL(`/wp-json/wp/v2/pages/${entry.wp_id}`, site);
    endpoint.searchParams.set('_fields', 'id,link,title,content,status,modified_gmt');

    const response = await fetch(endpoint, {
      headers: { Accept: 'application/json', 'User-Agent': 'WPKnowledgeChat-KB-Builder/0.1' },
      redirect: 'error',
      signal: AbortSignal.timeout(20_000),
    });
    if (!response.ok) {
      console.warn(`SKIP ${entry.id}: HTTP ${response.status}`);
      continue;
    }
    const page = (await response.json()) as {
      link: string;
      status: string;
      modified_gmt: string;
      title: { rendered: string };
      content: { rendered: string };
    };
    if (page.status !== 'publish') {
      console.warn(`SKIP ${entry.id}: status "${page.status}" is not publish`);
      continue;
    }

    const patterns = [...globalPatterns, ...(entry.exclude_patterns ?? []).map((p) => new RegExp(p, 'i'))];
    const text = htmlToText(page.content.rendered, { excludePatterns: patterns });
    if (text.length < 40) {
      console.warn(`SKIP ${entry.id}: page has no meaningful body text (${text.length} chars)`);
      continue;
    }

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
  }

  if (documents.length === 0) throw new Error('No documents were built; refusing to write an empty knowledge base.');

  const store = new KnowledgeBaseStore(allowedHosts);
  store.apply({ upserts: documents, deleteIds: [], replaceAll: true });
  await writeKnowledgeBaseFile(outPath, store.toFile());
  console.log(`Wrote ${documents.length} documents (${store.chunks().length} chunks) to ${outPath}`);
}

main().catch((err: unknown) => {
  console.error(`kb:build failed: ${err instanceof Error ? err.message : String(err)}`);
  process.exit(1);
});
