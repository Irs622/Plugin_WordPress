/**
 * Environment configuration. Every value is validated at startup so the
 * service fails fast instead of running half-configured. Error messages
 * name the offending variable but never echo its value.
 */

export type LogLevel = 'debug' | 'info' | 'warn' | 'error';

export interface Config {
  host: string;
  port: number;
  geminiApiKey: string;
  geminiModel: string;
  geminiApiBaseUrl: string;
  geminiTimeoutMs: number;
  geminiMaxOutputTokens: number;
  chatSharedSecret: string;
  indexApiToken: string;
  allowedSourceHosts: string[];
  kbPath: string;
  maxMessageChars: number;
  rateLimitPerClientPerMinute: number;
  rateLimitGlobalPerMinute: number;
  dailyRequestLimit: number;
  signatureMaxSkewSeconds: number;
  retrievalMinScore: number;
  retrievalTopK: number;
  logLevel: LogLevel;
}

export class ConfigError extends Error {
  constructor(public readonly problems: string[]) {
    super(`Invalid configuration: ${problems.join('; ')}`);
    this.name = 'ConfigError';
  }
}

type Env = Record<string, string | undefined>;

const MIN_SECRET_LENGTH = 32;

export function loadConfig(env: Env = process.env): Config {
  const problems: string[] = [];

  const required = (name: string): string => {
    const value = env[name]?.trim();
    if (!value) {
      problems.push(`${name} is required`);
      return '';
    }
    return value;
  };

  const secret = (name: string): string => {
    const value = required(name);
    if (value && value.length < MIN_SECRET_LENGTH) {
      problems.push(`${name} must be at least ${MIN_SECRET_LENGTH} characters`);
    }
    return value;
  };

  const int = (name: string, fallback: number, min: number, max: number): number => {
    const raw = env[name]?.trim();
    if (!raw) return fallback;
    const value = Number(raw);
    if (!Number.isInteger(value) || value < min || value > max) {
      problems.push(`${name} must be an integer between ${min} and ${max}`);
      return fallback;
    }
    return value;
  };

  const num = (name: string, fallback: number, min: number, max: number): number => {
    const raw = env[name]?.trim();
    if (!raw) return fallback;
    const value = Number(raw);
    if (!Number.isFinite(value) || value < min || value > max) {
      problems.push(`${name} must be a number between ${min} and ${max}`);
      return fallback;
    }
    return value;
  };

  const geminiApiKey = required('GEMINI_API_KEY');
  const chatSharedSecret = secret('CHAT_SHARED_SECRET');
  const indexApiToken = secret('INDEX_API_TOKEN');
  if (chatSharedSecret && chatSharedSecret === indexApiToken) {
    problems.push('CHAT_SHARED_SECRET and INDEX_API_TOKEN must be different');
  }

  const geminiApiBaseUrl = (env.GEMINI_API_BASE_URL?.trim() || 'https://generativelanguage.googleapis.com').replace(/\/+$/, '');
  try {
    const parsed = new URL(geminiApiBaseUrl);
    const isLocal = parsed.hostname === '127.0.0.1' || parsed.hostname === 'localhost';
    if (parsed.protocol !== 'https:' && !(isLocal && parsed.protocol === 'http:')) {
      problems.push('GEMINI_API_BASE_URL must use https (http is allowed only for localhost test doubles)');
    }
  } catch {
    problems.push('GEMINI_API_BASE_URL must be a valid URL');
  }

  const geminiModel = env.GEMINI_MODEL?.trim() || 'gemini-2.5-flash';
  if (!/^[a-z0-9][a-z0-9.\-]{1,80}$/i.test(geminiModel)) {
    problems.push('GEMINI_MODEL contains invalid characters');
  }

  const allowedSourceHosts = (env.ALLOWED_SOURCE_HOSTS?.trim() || 'inpartnerglobal.com,www.inpartnerglobal.com,inpartner.id,www.inpartner.id')
    .split(',')
    .map((h) => h.trim().toLowerCase())
    .filter(Boolean);
  for (const host of allowedSourceHosts) {
    if (!/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/.test(host)) {
      problems.push('ALLOWED_SOURCE_HOSTS contains an invalid hostname');
      break;
    }
  }

  const logLevel = (env.LOG_LEVEL?.trim() || 'info') as LogLevel;
  if (!['debug', 'info', 'warn', 'error'].includes(logLevel)) {
    problems.push('LOG_LEVEL must be one of debug, info, warn, error');
  }

  const config: Config = {
    host: env.HOST?.trim() || '127.0.0.1',
    port: int('PORT', 8787, 1, 65535),
    geminiApiKey,
    geminiModel,
    geminiApiBaseUrl,
    geminiTimeoutMs: int('GEMINI_TIMEOUT_MS', 12000, 1000, 60000),
    geminiMaxOutputTokens: int('GEMINI_MAX_OUTPUT_TOKENS', 600, 64, 4096),
    chatSharedSecret,
    indexApiToken,
    allowedSourceHosts,
    kbPath: env.KB_PATH?.trim() || 'data/kb.json',
    maxMessageChars: int('MAX_MESSAGE_CHARS', 500, 20, 4000),
    rateLimitPerClientPerMinute: int('RATE_LIMIT_PER_CLIENT_PER_MINUTE', 8, 1, 1000),
    rateLimitGlobalPerMinute: int('RATE_LIMIT_GLOBAL_PER_MINUTE', 60, 1, 100000),
    dailyRequestLimit: int('DAILY_REQUEST_LIMIT', 1000, 1, 10000000),
    signatureMaxSkewSeconds: int('SIGNATURE_MAX_SKEW_SECONDS', 300, 30, 3600),
    retrievalMinScore: num('RETRIEVAL_MIN_SCORE', 1.0, 0, 100),
    retrievalTopK: int('RETRIEVAL_TOP_K', 4, 1, 10),
    logLevel,
  };

  if (problems.length > 0) {
    throw new ConfigError(problems);
  }
  return config;
}
