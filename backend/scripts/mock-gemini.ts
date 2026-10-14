/**
 * Local stand-in for the Gemini generateContent endpoint, for tests and
 * offline end-to-end runs. It is NOT a model: it deterministically cites the
 * first source it receives, which exercises the full request/response path.
 *
 * Triggers in the question text: "__slow__" (sleeps past timeouts),
 * "__error__" (HTTP 500), "__garbage__" (malformed output).
 *
 * Usage: node dist/scripts/mock-gemini.js [port]   (default 8790)
 */
import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { pathToFileURL } from 'node:url';

export function startMockGemini(port = 0, slowMs = 3000): Promise<{ server: Server; url: string; requests: { key: string; path: string }[] }> {
  const requests: { key: string; path: string }[] = [];
  const server = createServer((req, res) => {
    const chunks: Buffer[] = [];
    req.on('data', (c: Buffer) => chunks.push(c));
    req.on('end', async () => {
      requests.push({ key: String(req.headers['x-goog-api-key'] ?? ''), path: req.url ?? '' });
      let userText = '';
      try {
        const body = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        userText = body.contents?.[0]?.parts?.[0]?.text ?? '';
      } catch {
        res.writeHead(400).end();
        return;
      }
      const question = /<question>\n([\s\S]*?)\n<\/question>/.exec(userText)?.[1] ?? '';
      if (question.includes('__slow__')) await new Promise((r) => setTimeout(r, slowMs));
      if (question.includes('__error__')) {
        res.writeHead(500, { 'Content-Type': 'application/json' }).end('{"error":{"message":"internal"}}');
        return;
      }
      const firstSource = /<source id="S1" title="([^"]*)">\n([^\n]*)/.exec(userText);
      const output = question.includes('__garbage__')
        ? 'this is not json'
        : firstSource
          ? JSON.stringify({ answer: `According to the "${firstSource[1]}" page: ${firstSource[2]}`, source_ids: ['S1'], grounded: true })
          : JSON.stringify({ answer: 'Not available.', source_ids: [], grounded: false });
      const payload = JSON.stringify({ candidates: [{ finishReason: 'STOP', content: { role: 'model', parts: [{ text: output }] } }] });
      if (!res.destroyed) res.writeHead(200, { 'Content-Type': 'application/json' }).end(payload);
    });
  });
  return new Promise((resolve) =>
    server.listen(port, '127.0.0.1', () => {
      const { port: actual } = server.address() as AddressInfo;
      resolve({ server, url: `http://127.0.0.1:${actual}`, requests });
    }),
  );
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const port = Number(process.argv[2] ?? 8790);
  startMockGemini(port, 20_000).then(({ url }) => console.log(`Mock Gemini listening on ${url}`));
}
