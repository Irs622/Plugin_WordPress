import type { IncomingMessage, ServerResponse } from 'node:http';
import type { SignatureVerifier } from '../lib/auth.js';
import { HttpError, headerValue, isPlainObject, parseJson, readRawBody, requireJsonContentType, sendJson } from '../lib/http.js';
import type { Logger } from '../lib/logger.js';
import type { FixedWindowLimiter } from '../middleware/rate-limit.js';
import { BudgetExceededError, LANGUAGES, type ChatHistoryTurn, type ChatService, type Language } from '../services/chat.js';
import type { KnowledgeBaseStore } from '../services/knowledge-base.js';
import { LlmError } from '../services/llm.js';

export const CHAT_BODY_LIMIT_BYTES = 8 * 1024;

export interface ChatRouteDeps {
  verifier: SignatureVerifier;
  clientLimiter: FixedWindowLimiter;
  globalLimiter: FixedWindowLimiter;
  chat: ChatService;
  store: KnowledgeBaseStore;
  logger: Logger;
  maxMessageChars: number;
}

export function validateChatRequest(body: unknown, maxMessageChars: number): { message: string; language: Language; history?: ChatHistoryTurn[] } {
  if (!isPlainObject(body)) {
    throw new HttpError(400, 'invalid_request', 'Request body must be a JSON object.');
  }
  if (typeof body.message !== 'string') {
    throw new HttpError(400, 'invalid_message', 'message must be a string.');
  }
  // eslint-disable-next-line no-control-regex
  const message = body.message.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '').trim();
  if (message.length === 0) {
    throw new HttpError(400, 'invalid_message', 'message must not be empty.');
  }
  if ([...message].length > maxMessageChars) {
    throw new HttpError(400, 'message_too_long', `message must be at most ${maxMessageChars} characters.`);
  }
  const language = body.language ?? 'en';
  if (typeof language !== 'string' || !(LANGUAGES as readonly string[]).includes(language)) {
    throw new HttpError(400, 'invalid_language', `language must be one of: ${LANGUAGES.join(', ')}.`);
  }

  let history: ChatHistoryTurn[] | undefined;
  if ('history' in body && body.history !== undefined) {
    if (!Array.isArray(body.history)) {
      throw new HttpError(400, 'invalid_history', 'history must be an array.');
    }
    if (body.history.length > 4) {
      throw new HttpError(400, 'invalid_history', 'history must contain at most 4 turns.');
    }
    history = [];
    for (const item of body.history) {
      if (!isPlainObject(item) || (item.role !== 'user' && item.role !== 'model') || typeof item.text !== 'string') {
        throw new HttpError(400, 'invalid_history', 'Each history item must have role ("user" | "model") and text.');
      }
      // eslint-disable-next-line no-control-regex
      const cleanText = item.text.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '').trim();
      const limit = item.role === 'model' ? 1500 : maxMessageChars;
      if ([...cleanText].length > limit) {
        throw new HttpError(400, 'invalid_history', `History text exceeds maximum allowed length.`);
      }
      history.push({ role: item.role as 'user' | 'model', text: cleanText });
    }
  }

  return { message, language: language as Language, ...(history ? { history } : {}) };
}

export async function handleChat(req: IncomingMessage, res: ServerResponse, deps: ChatRouteDeps): Promise<void> {
  const started = Date.now();
  requireJsonContentType(req);
  const raw = await readRawBody(req, CHAT_BODY_LIMIT_BYTES);

  const clientId = headerValue(req, 'x-wkc-client');
  const failure = deps.verifier.verify(
    headerValue(req, 'x-wkc-timestamp'),
    headerValue(req, 'x-wkc-nonce'),
    clientId,
    headerValue(req, 'x-wkc-signature'),
    raw,
  );
  if (failure) {
    deps.logger.warn('chat_auth_failed', { reason: failure });
    throw new HttpError(401, 'unauthorized', 'Request is not authorised.');
  }

  for (const [limiter, key, scope] of [
    [deps.clientLimiter, clientId || 'anonymous', 'client'],
    [deps.globalLimiter, 'global', 'global'],
  ] as const) {
    const result = limiter.hit(key);
    if (!result.allowed) {
      deps.logger.warn('rate_limited', { scope });
      throw new HttpError(429, 'rate_limited', 'Too many requests. Please try again shortly.', {
        'Retry-After': String(result.retryAfterSeconds),
      });
    }
  }

  const { message, language, history } = validateChatRequest(parseJson(raw), deps.maxMessageChars);

  if (deps.store.size === 0) {
    throw new HttpError(503, 'knowledge_base_unavailable', 'The assistant is not available right now.');
  }

  try {
    const result = await deps.chat.answer(message, language, history);
    deps.logger.info('chat_completed', {
      outcome: result.outcome,
      reason: result.reason,
      language,
      message_chars: message.length,
      sources: result.sources.length,
      latency_ms: Date.now() - started,
    });
    sendJson(res, 200, { answer: result.answer, sources: result.sources, grounded: result.grounded });
  } catch (err) {
    if (err instanceof BudgetExceededError) {
      deps.logger.warn('rate_limited', { scope: 'daily' });
      throw new HttpError(429, 'daily_limit_reached', 'The assistant has reached its daily limit. Please try again tomorrow.', {
        'Retry-After': String(err.retryAfterSeconds),
      });
    }
    if (err instanceof LlmError) {
      deps.logger.error('llm_failed', { kind: err.kind, provider_status: err.providerStatus, latency_ms: Date.now() - started });
      if (err.kind === 'timeout') {
        throw new HttpError(504, 'provider_timeout', 'The assistant took too long to respond. Please try again.');
      }
      throw new HttpError(502, 'provider_unavailable', 'The assistant is temporarily unavailable. Please try again later.');
    }
    throw err;
  }
}
