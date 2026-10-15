import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { randomBytes } from 'node:crypto';
import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createApp } from '../src/app.js';
import { loadConfig, type Config } from '../src/config/env.js';
import { computeSignature } from '../src/lib/auth.js';
import { silentLogger } from '../src/lib/logger.js';
import { KnowledgeBaseStore, type KnowledgeDocument } from '../src/services/knowledge-base.js';
import type { LlmClient, LlmRequest } from '../src/services/llm.js';

export const CHAT_SECRET = 'c'.repeat(40);
export const INDEX_TOKEN = 'i'.repeat(40);
export const GEMINI_KEY = 'AIzaTEST_fake_key_0123456789abcdef';

export const FIXTURE_DOCS: KnowledgeDocument[] = [
  {
    id: 'page-1-home',
    title: 'Home',
    url: 'https://inpartnerglobal.com/',
    text: 'Strategic Advisory to Accelerate Sustainable and Scalable Growth\nCorporate Advisory\nSupport critical financial decisions, investments, and business structuring.',
    category: 'home',
    status: 'publish',
    updated_at: '2026-09-23T03:16:07.000Z',
  },
  {
    id: 'page-2-distribution',
    title: 'Distribution Partner Sourcing Advisory',
    url: 'https://inpartnerglobal.com/distribution-partner-sourcing-advisory/',
    text: 'Partner Identification\nSource and shortlist relevant distributors, resellers, and channel partners.\nDue Diligence\nAssess credibility, capabilities, and commercial alignment with your goals.',
    category: 'service',
    status: 'publish',
    updated_at: '2026-09-23T08:06:08.000Z',
  },
  {
    id: 'page-3-contact',
    title: 'Contact',
    url: 'https://inpartnerglobal.com/contact/',
    text: 'Contact Us\nEmail Us\ncorporatesecretary@inpartner.id\nPakuwon Tower Lantai 10, Jakarta Selatan.',
    category: 'contact',
    status: 'publish',
    updated_at: '2026-06-04T03:46:49.000Z',
  },
];

export function baseEnv(overrides: Record<string, string> = {}): Record<string, string> {
  return {
    GEMINI_API_KEY: GEMINI_KEY,
    CHAT_SHARED_SECRET: CHAT_SECRET,
    INDEX_API_TOKEN: INDEX_TOKEN,
    LOG_LEVEL: 'error',
    ...overrides,
  };
}

export function testConfig(overrides: Record<string, string> = {}): Config {
  return loadConfig(baseEnv(overrides));
}

/** Scriptable LLM double that records every request. */
export class FakeLlm implements LlmClient {
  calls: LlmRequest[] = [];
  constructor(public handler: (req: LlmRequest) => Promise<string> | string = () => JSON.stringify({ answer: 'Inpartner offers advisory services.', source_ids: ['S1'], grounded: true })) {}
  async generate(req: LlmRequest): Promise<string> {
    this.calls.push(req);
    return this.handler(req);
  }
}

export interface TestServer {
  url: string;
  server: Server;
  store: KnowledgeBaseStore;
  config: Config;
  llm: FakeLlm;
  close(): Promise<void>;
}

export async function startTestServer(options: {
  env?: Record<string, string>;
  llm?: LlmClient;
  docs?: KnowledgeDocument[];
  now?: () => number;
  indexingEnabled?: boolean;
} = {}): Promise<TestServer> {
  const dir = await mkdtemp(join(tmpdir(), 'wkc-test-'));
  const config = testConfig({ KB_PATH: join(dir, 'kb.json'), ...options.env });
  const store = new KnowledgeBaseStore(config.allowedSourceHosts);
  store.loadFrom({ documents: options.docs ?? FIXTURE_DOCS });
  const llm = (options.llm as FakeLlm) ?? new FakeLlm();
  const server = createServer(createApp({ config, store, llm, logger: silentLogger, now: options.now, indexingEnabled: options.indexingEnabled }));
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address() as AddressInfo;
  return {
    url: `http://127.0.0.1:${port}`,
    server,
    store,
    config,
    llm,
    close: () => new Promise((resolve) => server.close(() => resolve())),
  };
}

export function signedHeaders(body: string, opts: { secret?: string; timestamp?: number; clientId?: string; nonce?: string } = {}): Record<string, string> {
  const timestamp = String(opts.timestamp ?? Math.floor(Date.now() / 1000));
  const clientId = opts.clientId ?? 'client-abc';
  const nonce = opts.nonce ?? randomBytes(16).toString('hex');
  return {
    'Content-Type': 'application/json',
    'X-WKC-Timestamp': timestamp,
    'X-WKC-Nonce': nonce,
    'X-WKC-Client': clientId,
    'X-WKC-Signature': computeSignature(opts.secret ?? CHAT_SECRET, timestamp, nonce, clientId, body),
  };
}

export async function postChat(baseUrl: string, payload: unknown, opts: Parameters<typeof signedHeaders>[1] = {}): Promise<Response> {
  const body = typeof payload === 'string' ? payload : JSON.stringify(payload);
  return fetch(`${baseUrl}/api/chat`, { method: 'POST', headers: signedHeaders(body, opts), body });
}

export async function postIndex(baseUrl: string, payload: unknown, token = INDEX_TOKEN): Promise<Response> {
  return fetch(`${baseUrl}/api/index`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
    body: JSON.stringify(payload),
  });
}
