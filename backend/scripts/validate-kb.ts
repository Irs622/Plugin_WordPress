/**
 * Validates a knowledge-base snapshot against the approved source list
 * without any network access. Exits non-zero on any error, so it can gate
 * deployments and CI.
 *
 * Usage: npm run kb:validate [-- --sources=data/sources.json --kb=data/kb.json]
 */
import { readFile } from 'node:fs/promises';
import { auditKnowledgeBase, parseSourcesFile } from '../src/services/kb-audit.js';

function arg(name: string, fallback: string): string {
  const match = process.argv.find((a) => a.startsWith(`--${name}=`));
  return match ? match.slice(name.length + 3) : fallback;
}

async function main(): Promise<void> {
  const sources = parseSourcesFile(JSON.parse(await readFile(arg('sources', 'data/sources.json'), 'utf8')));
  const kbPath = arg('kb', 'data/kb.json');
  const kb = JSON.parse(await readFile(kbPath, 'utf8')) as { documents?: unknown[] };
  const host = new URL(sources.site).hostname;
  const hosts = [host, host.startsWith('www.') ? host.slice(4) : `www.${host}`];

  const { errors, warnings } = auditKnowledgeBase(kb, sources, hosts);
  for (const w of warnings) console.warn(`WARN  ${w}`);
  for (const e of errors) console.error(`ERROR ${e}`);
  console.log(`${kbPath}: ${kb.documents?.length ?? 0} documents, ${errors.length} error(s), ${warnings.length} warning(s)`);
  if (errors.length > 0) process.exit(1);
}

main().catch((err: unknown) => {
  console.error(`kb:validate failed: ${err instanceof Error ? err.message : String(err)}`);
  process.exit(1);
});
