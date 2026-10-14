/**
 * In-memory rate limiting. Suitable for a single backend instance; a
 * multi-instance deployment needs a shared store (see docs/deployment.md).
 */

export interface LimitResult {
  allowed: boolean;
  retryAfterSeconds: number;
}

export class FixedWindowLimiter {
  private readonly windows = new Map<string, { start: number; count: number }>();

  constructor(
    private readonly limit: number,
    private readonly windowMs: number,
    private readonly now: () => number = () => Date.now(),
    private readonly maxKeys = 50000,
  ) {}

  hit(key: string): LimitResult {
    const now = this.now();
    let window = this.windows.get(key);
    if (!window || now - window.start >= this.windowMs) {
      if (!window && this.windows.size >= this.maxKeys) this.prune(now);
      window = { start: now, count: 0 };
      this.windows.set(key, window);
    }
    if (window.count >= this.limit) {
      return { allowed: false, retryAfterSeconds: Math.max(1, Math.ceil((window.start + this.windowMs - now) / 1000)) };
    }
    window.count += 1;
    return { allowed: true, retryAfterSeconds: 0 };
  }

  private prune(now: number): void {
    for (const [key, window] of this.windows) {
      if (now - window.start >= this.windowMs) this.windows.delete(key);
    }
    // Under a flood of unique keys, fail towards protecting memory.
    if (this.windows.size >= this.maxKeys) this.windows.clear();
  }
}

/** Caps total chat requests per UTC day to bound provider cost. */
export class DailyBudget {
  private day = '';
  private used = 0;

  constructor(
    private readonly limit: number,
    private readonly now: () => number = () => Date.now(),
  ) {}

  consume(): LimitResult {
    const date = new Date(this.now());
    const today = date.toISOString().slice(0, 10);
    if (today !== this.day) {
      this.day = today;
      this.used = 0;
    }
    if (this.used >= this.limit) {
      const tomorrow = Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate() + 1);
      return { allowed: false, retryAfterSeconds: Math.max(1, Math.ceil((tomorrow - date.getTime()) / 1000)) };
    }
    this.used += 1;
    return { allowed: true, retryAfterSeconds: 0 };
  }
}
