import { createHmac, timingSafeEqual } from 'node:crypto';

/**
 * Request signing between the WordPress plugin and this backend.
 *
 * signature = hex(HMAC-SHA256(secret, `${timestamp}.${nonce}.${clientId}.${rawBody}`))
 *
 * The timestamp bounds the replay window, the random nonce makes every
 * legitimate request unique, and a short-lived cache of seen signatures
 * rejects exact replays inside that window.
 */

export function computeSignature(secret: string, timestamp: string, nonce: string, clientId: string, rawBody: string): string {
  return createHmac('sha256', secret).update(`${timestamp}.${nonce}.${clientId}.${rawBody}`).digest('hex');
}

export function safeEqual(a: string, b: string): boolean {
  const left = Buffer.from(a, 'utf8');
  const right = Buffer.from(b, 'utf8');
  if (left.length !== right.length) {
    // Still run a comparison so timing does not reveal the length mismatch early.
    timingSafeEqual(left, left);
    return false;
  }
  return timingSafeEqual(left, right);
}

export type SignatureFailure = 'missing' | 'malformed' | 'expired' | 'invalid' | 'replayed';

export class SignatureVerifier {
  private readonly seen = new Map<string, number>();

  constructor(
    private readonly secret: string,
    private readonly maxSkewSeconds: number,
    private readonly now: () => number = () => Date.now(),
  ) {}

  verify(timestamp: string, nonce: string, clientId: string, signature: string, rawBody: string): SignatureFailure | null {
    if (!timestamp || !nonce || !signature) return 'missing';
    if (!/^[a-f0-9]{16,64}$/.test(nonce)) return 'malformed';
    if (!/^\d{9,11}$/.test(timestamp) || !/^[a-f0-9]{64}$/.test(signature)) return 'malformed';
    if (!/^[a-zA-Z0-9_-]{0,128}$/.test(clientId)) return 'malformed';

    const nowSeconds = Math.floor(this.now() / 1000);
    if (Math.abs(nowSeconds - Number(timestamp)) > this.maxSkewSeconds) return 'expired';

    const expected = computeSignature(this.secret, timestamp, nonce, clientId, rawBody);
    if (!safeEqual(expected, signature)) return 'invalid';

    this.prune(nowSeconds);
    if (this.seen.has(signature)) return 'replayed';
    this.seen.set(signature, nowSeconds);
    return null;
  }

  private prune(nowSeconds: number): void {
    if (this.seen.size < 1000) return;
    for (const [sig, at] of this.seen) {
      if (nowSeconds - at > this.maxSkewSeconds) this.seen.delete(sig);
    }
  }
}

export function verifyBearerToken(authorizationHeader: string, expectedToken: string): boolean {
  const match = /^Bearer\s+(\S+)$/i.exec(authorizationHeader.trim());
  if (!match?.[1]) return false;
  return safeEqual(match[1], expectedToken);
}
