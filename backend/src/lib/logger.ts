import type { LogLevel } from '../config/env.js';

/**
 * Structured JSON logger. Callers pass operational metadata only: never
 * visitor message text, IP addresses, secrets or provider payloads.
 */

const LEVELS: Record<LogLevel, number> = { debug: 10, info: 20, warn: 30, error: 40 };

export interface Logger {
  debug(event: string, fields?: Record<string, unknown>): void;
  info(event: string, fields?: Record<string, unknown>): void;
  warn(event: string, fields?: Record<string, unknown>): void;
  error(event: string, fields?: Record<string, unknown>): void;
}

export function createLogger(level: LogLevel, sink: (line: string) => void = (line) => process.stdout.write(line + '\n')): Logger {
  const threshold = LEVELS[level];
  const log = (lvl: LogLevel) => (event: string, fields: Record<string, unknown> = {}) => {
    if (LEVELS[lvl] < threshold) return;
    sink(JSON.stringify({ time: new Date().toISOString(), level: lvl, event, ...fields }));
  };
  return { debug: log('debug'), info: log('info'), warn: log('warn'), error: log('error') };
}

export const silentLogger: Logger = { debug() {}, info() {}, warn() {}, error() {} };
