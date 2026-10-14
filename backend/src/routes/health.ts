import type { IncomingMessage, ServerResponse } from 'node:http';
import type { SignatureVerifier } from '../lib/auth.js';
import { HttpError, headerValue, sendJson } from '../lib/http.js';
import type { Logger } from '../lib/logger.js';
import type { KnowledgeBaseStore } from '../services/knowledge-base.js';

export interface HealthRouteDeps {
  store: KnowledgeBaseStore;
  verifier: SignatureVerifier;
  logger: Logger;
}

/**
 * Minimal operational status. Deliberately exposes no versions, config or counts.
 *
 * Unsigned requests (load balancers, uptime monitors) get the status only.
 * A request carrying the X-WKC-* signature headers (the WordPress "Test
 * connection" button signs an empty body) is also authenticated, so a wrong
 * shared secret shows up as 401 instead of a misleading "ok".
 */
export function handleHealth(req: IncomingMessage, res: ServerResponse, deps: HealthRouteDeps): void {
  if (headerValue(req, 'x-wkc-signature')) {
    const failure = deps.verifier.verify(
      headerValue(req, 'x-wkc-timestamp'),
      headerValue(req, 'x-wkc-nonce'),
      headerValue(req, 'x-wkc-client'),
      headerValue(req, 'x-wkc-signature'),
      '',
    );
    if (failure) {
      deps.logger.warn('health_auth_failed', { reason: failure });
      throw new HttpError(401, 'unauthorized', 'Request is not authorised.');
    }
  }
  const ready = deps.store.size > 0;
  sendJson(res, ready ? 200 : 503, { status: ready ? 'ok' : 'degraded', knowledge_base: ready ? 'ready' : 'empty' });
}
