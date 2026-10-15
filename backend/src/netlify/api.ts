import type { RequestListener } from 'node:http';
import { createApp } from '../app.js';
import { ConfigError, loadConfig, type Config } from '../config/env.js';
import { createLogger, type Logger } from '../lib/logger.js';
import { KnowledgeBaseStore, readKnowledgeBaseFile } from '../services/knowledge-base.js';
import { GeminiClient } from '../services/llm.js';
import { handleWebRequest } from './adapter.js';

let appPromise: Promise<RequestListener> | null = null;

async function getApp(): Promise<RequestListener> {
  if (appPromise) return appPromise;

  appPromise = (async () => {
    const config: Config = loadConfig();
    const logger: Logger = createLogger(config.logLevel);
    const store = new KnowledgeBaseStore(config.allowedSourceHosts);

    try {
      const data = await readKnowledgeBaseFile(config.kbPath);
      const result = store.loadFrom(data);
      logger.info('kb_loaded', { documents: result.loaded, rejected: result.rejected });
    } catch (err) {
      logger.warn('kb_load_failed', { error: err instanceof Error ? err.name : 'unknown' });
    }

    const llm = new GeminiClient({
      apiKey: config.geminiApiKey,
      model: config.geminiModel,
      baseUrl: config.geminiApiBaseUrl,
      timeoutMs: config.geminiTimeoutMs,
      maxOutputTokens: config.geminiMaxOutputTokens,
    });

    return createApp({ config, store, llm, logger });
  })();

  return appPromise;
}

export default async function handler(req: Request): Promise<Response> {
  try {
    const listener = await getApp();
    return await handleWebRequest(req, listener);
  } catch (err) {
    if (err instanceof ConfigError) {
      return new Response(JSON.stringify({ error: { code: 'config_error', message: err.message } }), {
        status: 500,
        headers: { 'Content-Type': 'application/json' },
      });
    }
    return new Response(JSON.stringify({ error: { code: 'internal_error', message: 'Internal server error' } }), {
      status: 500,
      headers: { 'Content-Type': 'application/json' },
    });
  }
}

export const config = {
  path: ['/api/*', '/.netlify/functions/api/*'],
  preferStatic: true,
};
