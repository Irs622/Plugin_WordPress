import type { IncomingMessage, ServerResponse } from 'node:http';
import { verifyBearerToken } from '../lib/auth.js';
import { HttpError, headerValue, isPlainObject, parseJson, readRawBody, requireJsonContentType, sendJson } from '../lib/http.js';
import type { Logger } from '../lib/logger.js';
import type { FixedWindowLimiter } from '../middleware/rate-limit.js';
import {
  DocumentValidationError,
  validateDocument,
  writeKnowledgeBaseFile,
  type KnowledgeBaseStore,
  type KnowledgeDocument,
} from '../services/knowledge-base.js';
import type { Bm25Retriever } from '../services/retrieval.js';

export const INDEX_BODY_LIMIT_BYTES = 2 * 1024 * 1024;
const MAX_DOCUMENTS_PER_REQUEST = 200;

export interface IndexRouteDeps {
  indexApiToken: string;
  allowedSourceHosts: readonly string[];
  store: KnowledgeBaseStore;
  retriever: Bm25Retriever;
  kbPath: string;
  limiter: FixedWindowLimiter;
  logger: Logger;
  /** Serialises writes so concurrent index calls cannot interleave. */
  lock: { run<T>(fn: () => Promise<T>): Promise<T> };
  /**
   * False on multi-instance/serverless hosts: an update would reach only the
   * instance that handled the request (others keep serving removed content)
   * and is lost on the next cold start.
   */
  indexingEnabled?: boolean;
}

export function createLock(): IndexRouteDeps['lock'] {
  let tail: Promise<unknown> = Promise.resolve();
  return {
    run<T>(fn: () => Promise<T>): Promise<T> {
      const next = tail.then(fn, fn);
      tail = next.catch(() => undefined);
      return next;
    },
  };
}

export interface IndexUpdate {
  upserts: KnowledgeDocument[];
  deleteIds: string[];
  replaceAll: boolean;
}

export function validateIndexRequest(body: unknown, allowedHosts: readonly string[]): IndexUpdate {
  if (!isPlainObject(body)) throw new HttpError(400, 'invalid_request', 'Request body must be a JSON object.');

  const documents = body.documents ?? [];
  const deleteIds = body.delete_ids ?? [];
  const replaceAll = body.replace_all ?? false;

  if (!Array.isArray(documents) || documents.length > MAX_DOCUMENTS_PER_REQUEST) {
    throw new HttpError(400, 'invalid_request', `documents must be an array of at most ${MAX_DOCUMENTS_PER_REQUEST} items.`);
  }
  if (!Array.isArray(deleteIds) || deleteIds.length > MAX_DOCUMENTS_PER_REQUEST || !deleteIds.every((id) => typeof id === 'string' && /^[a-z0-9][a-z0-9._:-]{0,127}$/.test(id))) {
    throw new HttpError(400, 'invalid_request', 'delete_ids must be an array of valid document ids.');
  }
  if (typeof replaceAll !== 'boolean') throw new HttpError(400, 'invalid_request', 'replace_all must be a boolean.');
  if (replaceAll && documents.length === 0) {
    throw new HttpError(400, 'invalid_request', 'replace_all requires at least one document (refusing to empty the knowledge base).');
  }
  if (documents.length === 0 && deleteIds.length === 0) {
    throw new HttpError(400, 'invalid_request', 'Nothing to index: provide documents or delete_ids.');
  }

  const upserts: KnowledgeDocument[] = [];
  const details: { index: number; field: string; message: string }[] = [];
  const ids = new Set<string>();
  documents.forEach((raw, index) => {
    try {
      const doc = validateDocument(raw, allowedHosts);
      if (ids.has(doc.id)) throw new DocumentValidationError('id', 'Duplicate document id in request.');
      ids.add(doc.id);
      upserts.push(doc);
    } catch (err) {
      if (err instanceof DocumentValidationError) details.push({ index, field: err.field, message: err.message });
      else throw err;
    }
  });
  if (details.length > 0) {
    // All-or-nothing: a partially valid batch is rejected so the index never ends up half-updated.
    const error = new HttpError(422, 'invalid_document', 'One or more documents failed validation. Nothing was indexed.');
    (error as HttpError & { details?: unknown }).details = details.slice(0, 50);
    throw error;
  }
  return { upserts, deleteIds: deleteIds as string[], replaceAll };
}

export async function handleIndex(req: IncomingMessage, res: ServerResponse, deps: IndexRouteDeps): Promise<void> {
  if (!verifyBearerToken(headerValue(req, 'authorization'), deps.indexApiToken)) {
    req.resume();
    deps.logger.warn('index_auth_failed');
    throw new HttpError(401, 'unauthorized', 'Request is not authorised.', { 'WWW-Authenticate': 'Bearer' });
  }
  if (deps.indexingEnabled === false) {
    req.resume();
    throw new HttpError(
      501,
      'index_unsupported',
      'This deployment cannot apply knowledge-base updates at runtime (multi-instance host). Rebuild data/kb.json and redeploy.',
    );
  }
  const limit = deps.limiter.hit('index');
  if (!limit.allowed) {
    req.resume();
    throw new HttpError(429, 'rate_limited', 'Too many requests.', { 'Retry-After': String(limit.retryAfterSeconds) });
  }
  requireJsonContentType(req);
  const update = validateIndexRequest(parseJson(await readRawBody(req, INDEX_BODY_LIMIT_BYTES)), deps.allowedSourceHosts);

  const result = await deps.lock.run(async () => {
    const before = deps.store.toFile();
    let counts;
    try {
      counts = deps.store.apply(update);
    } catch (err) {
      if (err instanceof DocumentValidationError) throw new HttpError(422, 'invalid_document', err.message);
      throw err;
    }
    let persisted: { durable: boolean };
    try {
      persisted = await writeKnowledgeBaseFile(deps.kbPath, deps.store.toFile());
    } catch (err) {
      deps.store.loadFrom(before);
      deps.logger.error('kb_persist_failed', { error: err instanceof Error ? err.name : 'unknown' });
      throw new HttpError(500, 'persist_failed', 'The knowledge base could not be saved. No changes were applied.');
    }
    deps.retriever.rebuild(deps.store.chunks());
    if (!persisted.durable) deps.logger.warn('kb_persisted_ephemeral');
    return { ...counts, total: deps.store.size, persisted: persisted.durable ? 'file' : 'ephemeral' };
  });

  deps.logger.info('kb_indexed', result);
  sendJson(res, 200, result);
}
