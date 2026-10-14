import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { computeSignature, SignatureVerifier, verifyBearerToken } from '../src/lib/auth.js';
import { DailyBudget, FixedWindowLimiter } from '../src/middleware/rate-limit.js';

describe('SignatureVerifier', () => {
  const secret = 's'.repeat(40);
  const now = 1_790_000_000_000;
  const ts = String(now / 1000);
  const body = '{"message":"hi","language":"en"}';
  const nonce = '0123456789abcdef0123456789abcdef';

  it('accepts a valid signature once and rejects a replay', () => {
    const verifier = new SignatureVerifier(secret, 300, () => now);
    const sig = computeSignature(secret, ts, nonce, 'abc', body);
    assert.equal(verifier.verify(ts, nonce, 'abc', sig, body), null);
    assert.equal(verifier.verify(ts, nonce, 'abc', sig, body), 'replayed');
  });

  it('accepts identical bodies that carry different nonces', () => {
    const verifier = new SignatureVerifier(secret, 300, () => now);
    for (const n of ['a'.repeat(32), 'b'.repeat(32)]) {
      assert.equal(verifier.verify(ts, n, 'abc', computeSignature(secret, ts, n, 'abc', body), body), null);
    }
  });

  it('rejects missing, malformed, expired and tampered requests', () => {
    const verifier = new SignatureVerifier(secret, 300, () => now);
    const sig = computeSignature(secret, ts, nonce, 'abc', body);
    assert.equal(verifier.verify('', nonce, 'abc', sig, body), 'missing');
    assert.equal(verifier.verify(ts, nonce, 'abc', 'zz', body), 'malformed');
    assert.equal(verifier.verify(ts, 'short', 'abc', sig, body), 'malformed');
    assert.equal(verifier.verify(ts, '', 'abc', sig, body), 'missing');
    assert.equal(verifier.verify(ts, nonce, 'a b<c>', sig, body), 'malformed');
    const old = String(now / 1000 - 301);
    assert.equal(verifier.verify(old, nonce, 'abc', computeSignature(secret, old, nonce, 'abc', body), body), 'expired');
    assert.equal(verifier.verify(ts, nonce, 'abc', sig, body.replace('hi', 'yo')), 'invalid');
    assert.equal(verifier.verify(ts, nonce, 'other-client', sig, body), 'invalid');
    assert.equal(verifier.verify(ts, nonce, 'abc', computeSignature('w'.repeat(40), ts, nonce, 'abc', body), body), 'invalid');
  });
});

describe('verifyBearerToken', () => {
  it('requires an exact Bearer token', () => {
    const token = 't'.repeat(40);
    assert.equal(verifyBearerToken(`Bearer ${token}`, token), true);
    assert.equal(verifyBearerToken(`bearer ${token}`, token), true);
    assert.equal(verifyBearerToken(`Bearer ${token}x`, token), false);
    assert.equal(verifyBearerToken(`Basic ${token}`, token), false);
    assert.equal(verifyBearerToken('', token), false);
  });
});

describe('FixedWindowLimiter', () => {
  it('limits per key and resets after the window', () => {
    let now = 0;
    const limiter = new FixedWindowLimiter(2, 60_000, () => now);
    assert.equal(limiter.hit('a').allowed, true);
    assert.equal(limiter.hit('a').allowed, true);
    const blocked = limiter.hit('a');
    assert.equal(blocked.allowed, false);
    assert.equal(blocked.retryAfterSeconds, 60);
    assert.equal(limiter.hit('b').allowed, true, 'other keys are independent');
    now = 60_000;
    assert.equal(limiter.hit('a').allowed, true);
  });

  it('bounds memory under many unique keys', () => {
    const limiter = new FixedWindowLimiter(1, 60_000, () => 0, 100);
    for (let i = 0; i < 1000; i++) limiter.hit(`k${i}`);
    assert.ok((limiter as unknown as { windows: Map<string, unknown> }).windows.size <= 100);
  });
});

describe('DailyBudget', () => {
  it('caps requests per UTC day', () => {
    let now = Date.UTC(2026, 9, 14, 23, 59, 0);
    const budget = new DailyBudget(2, () => now);
    assert.equal(budget.consume().allowed, true);
    assert.equal(budget.consume().allowed, true);
    const blocked = budget.consume();
    assert.equal(blocked.allowed, false);
    assert.equal(blocked.retryAfterSeconds, 60);
    now = Date.UTC(2026, 9, 15, 0, 0, 1);
    assert.equal(budget.consume().allowed, true);
  });
});
