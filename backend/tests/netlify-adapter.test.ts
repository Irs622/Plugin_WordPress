import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { createApp } from '../src/app.js';
import { handleWebRequest } from '../src/netlify/adapter.js';
import { KnowledgeBaseStore } from '../src/services/knowledge-base.js';
import { FakeLlm, FIXTURE_DOCS, testConfig } from './helpers.js';
import { silentLogger } from '../src/lib/logger.js';

describe('handleWebRequest (Netlify adapter)', () => {
  const config = testConfig();
  const store = new KnowledgeBaseStore(config.allowedSourceHosts);
  store.loadFrom({ documents: FIXTURE_DOCS });
  const llm = new FakeLlm();
  const app = createApp({ config, store, llm, logger: silentLogger });

  it('routes GET /api/health through Web API Request/Response', async () => {
    const webReq = new Request('https://example.netlify.app/api/health', {
      method: 'GET',
    });
    const res = await handleWebRequest(webReq, app);
    assert.equal(res.status, 200);
    const body = await res.json() as { status: string; knowledge_base: string };
    assert.equal(body.status, 'ok');
    assert.equal(body.knowledge_base, 'ready');
  });

  it('routes GET /.netlify/functions/api/health by normalizing path', async () => {
    const webReq = new Request('https://example.netlify.app/.netlify/functions/api/health', {
      method: 'GET',
    });
    const res = await handleWebRequest(webReq, app);
    assert.equal(res.status, 200);
    const body = await res.json() as { status: string; knowledge_base: string };
    assert.equal(body.status, 'ok');
  });

  it('routes 404 for unknown endpoints through Web API Response', async () => {
    const webReq = new Request('https://example.netlify.app/api/unknown-endpoint', {
      method: 'GET',
    });
    const res = await handleWebRequest(webReq, app);
    assert.equal(res.status, 404);
  });

  it('rejects unsigned POST /api/chat with 401', async () => {
    const webReq = new Request('https://example.netlify.app/api/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: 'Hello' }),
    });
    const res = await handleWebRequest(webReq, app);
    assert.equal(res.status, 401);
  });
});

