import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { ConfigError, loadConfig } from '../src/config/env.js';
import { baseEnv, GEMINI_KEY } from './helpers.js';

describe('loadConfig', () => {
  it('loads a valid configuration with safe defaults', () => {
    const config = loadConfig(baseEnv());
    assert.equal(config.host, '127.0.0.1');
    assert.equal(config.port, 8787);
    assert.equal(config.maxMessageChars, 500);
    assert.equal(config.geminiApiBaseUrl, 'https://generativelanguage.googleapis.com');
    assert.deepEqual(config.allowedSourceHosts, ['inpartnerglobal.com', 'www.inpartnerglobal.com']);
  });

  it('reports every missing required variable without echoing values', () => {
    assert.throws(
      () => loadConfig({}),
      (err: unknown) => {
        assert.ok(err instanceof ConfigError);
        assert.deepEqual(err.problems, ['GEMINI_API_KEY is required', 'CHAT_SHARED_SECRET is required', 'INDEX_API_TOKEN is required']);
        return true;
      },
    );
  });

  it('rejects short secrets and identical chat/index secrets', () => {
    assert.throws(() => loadConfig(baseEnv({ CHAT_SHARED_SECRET: 'short' })), /CHAT_SHARED_SECRET must be at least 32/);
    const same = 'x'.repeat(40);
    assert.throws(() => loadConfig(baseEnv({ CHAT_SHARED_SECRET: same, INDEX_API_TOKEN: same })), /must be different/);
  });

  it('rejects invalid numbers, hosts and log levels', () => {
    assert.throws(() => loadConfig(baseEnv({ PORT: 'abc' })), /PORT must be an integer/);
    assert.throws(() => loadConfig(baseEnv({ MAX_MESSAGE_CHARS: '0' })), /MAX_MESSAGE_CHARS/);
    assert.throws(() => loadConfig(baseEnv({ ALLOWED_SOURCE_HOSTS: 'evil.com/path' })), /ALLOWED_SOURCE_HOSTS/);
    assert.throws(() => loadConfig(baseEnv({ LOG_LEVEL: 'trace' })), /LOG_LEVEL/);
  });

  it('allows plain http for the provider only on localhost', () => {
    assert.throws(() => loadConfig(baseEnv({ GEMINI_API_BASE_URL: 'http://example.com' })), /GEMINI_API_BASE_URL must use https/);
    assert.equal(loadConfig(baseEnv({ GEMINI_API_BASE_URL: 'http://127.0.0.1:9999/' })).geminiApiBaseUrl, 'http://127.0.0.1:9999');
  });

  it('never includes secret values in error messages', () => {
    try {
      loadConfig(baseEnv({ CHAT_SHARED_SECRET: 'tooShortSecretValue', PORT: 'nope' }));
      assert.fail('expected ConfigError');
    } catch (err) {
      assert.ok(err instanceof ConfigError);
      assert.ok(!err.message.includes('tooShortSecretValue'));
      assert.ok(!err.message.includes(GEMINI_KEY));
    }
  });
});
