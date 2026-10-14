import { createServer } from 'node:http';
import { createApp } from './app.js';
import { ConfigError, loadConfig } from './config/env.js';
import { createLogger } from './lib/logger.js';
import { KnowledgeBaseStore, readKnowledgeBaseFile } from './services/knowledge-base.js';
import { GeminiClient } from './services/llm.js';

async function main(): Promise<void> {
  let config;
  try {
    config = loadConfig();
  } catch (err) {
    if (err instanceof ConfigError) {
      process.stderr.write(`[wp-knowledge-chat] ${err.message}\n`);
      process.exit(78); // EX_CONFIG
    }
    throw err;
  }

  const logger = createLogger(config.logLevel);
  const store = new KnowledgeBaseStore(config.allowedSourceHosts);
  try {
    const result = store.loadFrom(await readKnowledgeBaseFile(config.kbPath));
    logger.info('kb_loaded', { documents: result.loaded, rejected: result.rejected });
  } catch (err) {
    // Start anyway: /api/health reports "degraded" and /api/index can populate the store.
    logger.warn('kb_load_failed', { error: err instanceof Error ? err.name : 'unknown' });
  }

  const llm = new GeminiClient({
    apiKey: config.geminiApiKey,
    model: config.geminiModel,
    baseUrl: config.geminiApiBaseUrl,
    timeoutMs: config.geminiTimeoutMs,
    maxOutputTokens: config.geminiMaxOutputTokens,
  });

  const server = createServer(createApp({ config, store, llm, logger }));
  server.requestTimeout = 30_000;
  server.headersTimeout = 10_000;
  server.keepAliveTimeout = 5_000;
  server.maxHeadersCount = 50;

  server.listen(config.port, config.host, () => {
    logger.info('server_started', { host: config.host, port: config.port });
  });

  const shutdown = (signal: string) => {
    logger.info('server_stopping', { signal });
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(1), 10_000).unref();
  };
  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));
}

main().catch(() => {
  process.stderr.write('[wp-knowledge-chat] fatal startup error\n');
  process.exit(1);
});
