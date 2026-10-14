/**
 * Gemini client over the public REST API (no SDK dependency). Applies an
 * overall deadline, at most one retry for transient failures, and never
 * surfaces provider payloads or credentials in errors.
 */

export type LlmErrorKind = 'timeout' | 'unavailable' | 'rate_limited' | 'auth' | 'blocked' | 'bad_response';

export class LlmError extends Error {
  constructor(public readonly kind: LlmErrorKind, public readonly providerStatus?: number) {
    super(`LLM request failed: ${kind}`);
    this.name = 'LlmError';
  }
}

export interface LlmRequest {
  systemInstruction: string;
  userContent: string;
}

export interface LlmClient {
  generate(request: LlmRequest): Promise<string>;
}

export interface GeminiOptions {
  apiKey: string;
  model: string;
  baseUrl: string;
  timeoutMs: number;
  maxOutputTokens: number;
  fetchImpl?: typeof fetch;
  sleep?: (ms: number) => Promise<void>;
}

/** JSON schema the model must follow; parsed and re-validated by the caller regardless. */
const RESPONSE_SCHEMA = {
  type: 'OBJECT',
  properties: {
    answer: { type: 'STRING' },
    source_ids: { type: 'ARRAY', items: { type: 'STRING' } },
    grounded: { type: 'BOOLEAN' },
  },
  required: ['answer', 'source_ids', 'grounded'],
};

const RETRYABLE_STATUS = new Set([429, 500, 502, 503, 504]);

export class GeminiClient implements LlmClient {
  private readonly fetchImpl: typeof fetch;
  private readonly sleep: (ms: number) => Promise<void>;

  constructor(private readonly options: GeminiOptions) {
    this.fetchImpl = options.fetchImpl ?? fetch;
    this.sleep = options.sleep ?? ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
  }

  async generate(request: LlmRequest): Promise<string> {
    const deadline = Date.now() + this.options.timeoutMs;
    let lastError: LlmError | undefined;

    for (let attempt = 0; attempt < 2; attempt++) {
      const remaining = deadline - Date.now();
      if (remaining <= 250) break;
      try {
        return await this.attempt(request, remaining);
      } catch (err) {
        lastError = err instanceof LlmError ? err : new LlmError('unavailable');
        const retryable =
          lastError.kind === 'unavailable' ||
          (lastError.kind === 'rate_limited' && attempt === 0) ||
          (lastError.providerStatus !== undefined && RETRYABLE_STATUS.has(lastError.providerStatus));
        if (!retryable || lastError.kind === 'timeout') break;
        await this.sleep(Math.min(400, Math.max(0, deadline - Date.now() - 250)));
      }
    }
    throw lastError ?? new LlmError('timeout');
  }

  private async attempt(request: LlmRequest, timeoutMs: number): Promise<string> {
    const url = `${this.options.baseUrl}/v1beta/models/${encodeURIComponent(this.options.model)}:generateContent`;
    const generationConfig: Record<string, unknown> = {
      temperature: 0.2,
      maxOutputTokens: this.options.maxOutputTokens,
      responseMimeType: 'application/json',
      responseSchema: RESPONSE_SCHEMA,
    };
    // Gemini 2.5 Flash spends output tokens on "thinking" by default; a short grounded answer does not need it.
    if (/^gemini-2\.5-flash/.test(this.options.model)) {
      generationConfig.thinkingConfig = { thinkingBudget: 0 };
    }

    const body = JSON.stringify({
      systemInstruction: { parts: [{ text: request.systemInstruction }] },
      contents: [{ role: 'user', parts: [{ text: request.userContent }] }],
      generationConfig,
    });

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    let response: Response;
    try {
      response = await this.fetchImpl(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'x-goog-api-key': this.options.apiKey },
        body,
        signal: controller.signal,
      });
    } catch (err) {
      if (controller.signal.aborted) throw new LlmError('timeout');
      throw new LlmError('unavailable');
    } finally {
      clearTimeout(timer);
    }

    if (!response.ok) {
      // Drain without reading into logs: provider error bodies can echo request content.
      await response.body?.cancel().catch(() => undefined);
      if (response.status === 429) throw new LlmError('rate_limited', 429);
      if (response.status === 401 || response.status === 403) throw new LlmError('auth', response.status);
      if (response.status === 400) throw new LlmError('bad_response', 400);
      throw new LlmError('unavailable', response.status);
    }

    let payload: unknown;
    try {
      payload = await response.json();
    } catch {
      throw new LlmError('bad_response');
    }
    return extractText(payload);
  }
}

function extractText(payload: unknown): string {
  const data = payload as {
    promptFeedback?: { blockReason?: string };
    candidates?: { finishReason?: string; content?: { parts?: { text?: unknown }[] } }[];
  };
  if (data?.promptFeedback?.blockReason) throw new LlmError('blocked');
  const candidate = data?.candidates?.[0];
  if (!candidate) throw new LlmError('bad_response');
  if (candidate.finishReason === 'SAFETY' || candidate.finishReason === 'PROHIBITED_CONTENT') {
    throw new LlmError('blocked');
  }
  const text = (candidate.content?.parts ?? [])
    .map((p) => (typeof p.text === 'string' ? p.text : ''))
    .join('')
    .trim();
  if (!text) throw new LlmError('bad_response');
  return text;
}
