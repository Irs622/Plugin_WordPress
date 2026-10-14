import type { ServerResponse } from 'node:http';
import { sendJson } from '../lib/http.js';
import type { KnowledgeBaseStore } from '../services/knowledge-base.js';

/** Minimal operational status. Deliberately exposes no versions, config or counts. */
export function handleHealth(res: ServerResponse, store: KnowledgeBaseStore): void {
  const ready = store.size > 0;
  sendJson(res, ready ? 200 : 503, { status: ready ? 'ok' : 'degraded', knowledge_base: ready ? 'ready' : 'empty' });
}
