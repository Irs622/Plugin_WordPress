import type { IncomingMessage, RequestListener, ServerResponse } from 'node:http';
import type { Config } from './config/env.js';
import { SignatureVerifier } from './lib/auth.js';
import { HttpError, sendError, sendJson } from './lib/http.js';
import type { Logger } from './lib/logger.js';
import { DailyBudget, FixedWindowLimiter } from './middleware/rate-limit.js';
import { handleChat } from './routes/chat.js';
import { handleHealth } from './routes/health.js';
import { createLock, handleIndex } from './routes/index.js';
import { ChatService } from './services/chat.js';
import type { KnowledgeBaseStore } from './services/knowledge-base.js';
import type { LlmClient } from './services/llm.js';
import { Bm25Retriever } from './services/retrieval.js';

export interface AppDeps {
  config: Config;
  store: KnowledgeBaseStore;
  llm: LlmClient;
  logger: Logger;
  now?: () => number;
}

export function createApp({ config, store, llm, logger, now = () => Date.now() }: AppDeps): RequestListener {
  const retriever = new Bm25Retriever(store.chunks());
  const chat = new ChatService({
    store,
    retriever,
    llm,
    logger,
    topK: config.retrievalTopK,
    minScore: config.retrievalMinScore,
    secrets: [config.geminiApiKey, config.chatSharedSecret, config.indexApiToken],
  });

  const chatDeps = {
    verifier: new SignatureVerifier(config.chatSharedSecret, config.signatureMaxSkewSeconds, now),
    clientLimiter: new FixedWindowLimiter(config.rateLimitPerClientPerMinute, 60_000, now),
    globalLimiter: new FixedWindowLimiter(config.rateLimitGlobalPerMinute, 60_000, now),
    dailyBudget: new DailyBudget(config.dailyRequestLimit, now),
    chat,
    store,
    logger,
    maxMessageChars: config.maxMessageChars,
  };

  const indexDeps = {
    indexApiToken: config.indexApiToken,
    allowedSourceHosts: config.allowedSourceHosts,
    store,
    retriever,
    kbPath: config.kbPath,
    limiter: new FixedWindowLimiter(30, 60_000, now),
    logger,
    lock: createLock(),
  };

  const routes: Record<string, { method: string; handle: (req: IncomingMessage, res: ServerResponse) => Promise<void> | void }> = {
    '/api/chat': { method: 'POST', handle: (req, res) => handleChat(req, res, chatDeps) },
    '/api/index': { method: 'POST', handle: (req, res) => handleIndex(req, res, indexDeps) },
    '/api/health': { method: 'GET', handle: (_req, res) => handleHealth(res, store) },
  };

  return (req, res) => {
    const pathname = (req.url ?? '/').split('?')[0] ?? '/';
    const route = routes[pathname];

    const run = async () => {
      if (!route) throw new HttpError(404, 'not_found', 'Not found.');
      if (req.method !== route.method) {
        throw new HttpError(405, 'method_not_allowed', 'Method not allowed.', { Allow: route.method });
      }
      await route.handle(req, res);
    };

    run().catch((err: unknown) => {
      if (err instanceof HttpError) {
        if (!req.complete) req.resume();
        const details = (err as HttpError & { details?: unknown }).details;
        if (details) {
          sendJson(res, err.status, { error: { code: err.code, message: err.message, details } }, err.headers);
        } else {
          sendError(res, err);
        }
        return;
      }
      logger.error('unhandled_error', { error: err instanceof Error ? err.name : 'unknown' });
      sendError(res, new HttpError(500, 'internal_error', 'An internal error occurred.'));
    });
  };
}
