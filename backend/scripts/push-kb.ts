/**
 * Pushes a reviewed knowledge-base snapshot to a running backend via
 * POST /api/index (replace_all). Reads the token from INDEX_API_TOKEN.
 *
 * Usage: BACKEND_URL=https://chat-api.example.com INDEX_API_TOKEN=... npm run kb:push [-- --file=data/kb.json]
 */
import { readFile } from 'node:fs/promises';

async function main(): Promise<void> {
  const fileArg = process.argv.find((a) => a.startsWith('--file='));
  const file = fileArg ? fileArg.slice('--file='.length) : 'data/kb.json';
  const backend = process.env.BACKEND_URL?.trim();
  const token = process.env.INDEX_API_TOKEN?.trim();
  if (!backend || !token) throw new Error('BACKEND_URL and INDEX_API_TOKEN must be set');

  const url = new URL('/api/index', backend);
  const isLocal = url.hostname === '127.0.0.1' || url.hostname === 'localhost';
  if (url.protocol !== 'https:' && !isLocal) throw new Error('BACKEND_URL must use https (http only for localhost)');

  const kb = JSON.parse(await readFile(file, 'utf8')) as { documents: unknown[] };
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
    body: JSON.stringify({ documents: kb.documents, replace_all: true }),
    signal: AbortSignal.timeout(30_000),
  });
  const body = await response.text();
  console.log(`HTTP ${response.status} ${body}`);
  if (!response.ok) process.exit(1);
}

main().catch((err: unknown) => {
  console.error(`kb:push failed: ${err instanceof Error ? err.message : String(err)}`);
  process.exit(1);
});
