import { createHash } from 'node:crypto';
import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';

/**
 * Knowledge-base documents and their persistence. Documents are validated
 * on every path into the store (file load and /api/index) so that only
 * published content from approved hosts can ever be cited.
 */

export const DOCUMENT_CATEGORIES = ['home', 'about', 'service', 'contact', 'faq', 'article'] as const;
export type DocumentCategory = (typeof DOCUMENT_CATEGORIES)[number];

export interface KnowledgeDocument {
  id: string;
  title: string;
  url: string;
  text: string;
  category: DocumentCategory;
  status: 'publish';
  updated_at: string;
}

export interface Chunk {
  id: string;
  docId: string;
  title: string;
  url: string;
  text: string;
}

export interface KnowledgeBaseFile {
  version: 1;
  generated_at: string;
  documents: KnowledgeDocument[];
}

export const LIMITS = {
  idMax: 128,
  titleMax: 300,
  textMax: 100_000,
  chunkTargetChars: 700,
  maxDocuments: 500,
};

export class DocumentValidationError extends Error {
  constructor(public readonly field: string, message: string) {
    super(message);
    this.name = 'DocumentValidationError';
  }
}

/**
 * Canonicalises a source URL and enforces the approved-host policy.
 * Returns null when the URL is not acceptable as a citation.
 */
export function canonicalizeSourceUrl(raw: unknown, allowedHosts: readonly string[]): string | null {
  if (typeof raw !== 'string' || raw.length > 2048) return null;
  let url: URL;
  try {
    url = new URL(raw);
  } catch {
    return null;
  }
  if (url.protocol !== 'https:') return null;
  if (url.username || url.password) return null;
  if (url.port && url.port !== '443') return null;
  const host = url.hostname.toLowerCase();
  if (!allowedHosts.includes(host)) return null;
  url.hash = '';
  url.port = '';
  return url.toString();
}

function controlCharsStripped(value: string): string {
  // eslint-disable-next-line no-control-regex
  return value.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F\u200B-\u200D\uFEFF]/g, '');
}

export function validateDocument(input: unknown, allowedHosts: readonly string[]): KnowledgeDocument {
  if (typeof input !== 'object' || input === null || Array.isArray(input)) {
    throw new DocumentValidationError('document', 'Each document must be an object.');
  }
  const doc = input as Record<string, unknown>;

  const id = doc.id;
  if (typeof id !== 'string' || !/^[a-z0-9][a-z0-9._:-]*$/.test(id) || id.length > LIMITS.idMax) {
    throw new DocumentValidationError('id', 'id must match ^[a-z0-9][a-z0-9._:-]*$ (max 128 chars).');
  }

  const title = typeof doc.title === 'string' ? controlCharsStripped(doc.title).trim() : '';
  if (!title || title.length > LIMITS.titleMax) {
    throw new DocumentValidationError('title', 'title is required (max 300 chars).');
  }

  const url = canonicalizeSourceUrl(doc.url, allowedHosts);
  if (!url) {
    throw new DocumentValidationError('url', 'url must be an https URL on an approved host without credentials.');
  }

  const text = typeof doc.text === 'string' ? controlCharsStripped(doc.text).trim() : '';
  if (!text || text.length > LIMITS.textMax) {
    throw new DocumentValidationError('text', `text is required (max ${LIMITS.textMax} chars).`);
  }

  if (typeof doc.category !== 'string' || !(DOCUMENT_CATEGORIES as readonly string[]).includes(doc.category)) {
    throw new DocumentValidationError('category', `category must be one of ${DOCUMENT_CATEGORIES.join(', ')}.`);
  }

  if (doc.status !== 'publish') {
    throw new DocumentValidationError('status', 'Only published content (status "publish") can be indexed.');
  }

  if (typeof doc.updated_at !== 'string' || Number.isNaN(Date.parse(doc.updated_at))) {
    throw new DocumentValidationError('updated_at', 'updated_at must be an ISO-8601 timestamp.');
  }

  return {
    id,
    title,
    url,
    text,
    category: doc.category as DocumentCategory,
    status: 'publish',
    updated_at: new Date(doc.updated_at).toISOString(),
  };
}

export function documentFingerprint(doc: KnowledgeDocument): string {
  return createHash('sha256')
    .update(JSON.stringify([doc.title, doc.url, doc.text, doc.category, doc.updated_at]))
    .digest('hex');
}

function splitLongLine(line: string, maxLen: number): string[] {
  const pieces: string[] = [];
  let remaining = line.trim();
  while (remaining.length > maxLen) {
    let breakIdx = remaining.lastIndexOf(' ', maxLen);
    if (breakIdx <= 0) {
      breakIdx = maxLen;
    }
    const part = remaining.slice(0, breakIdx).trim();
    if (part) pieces.push(part);
    remaining = remaining.slice(breakIdx).trim();
  }
  if (remaining.length > 0) {
    pieces.push(remaining);
  }
  return pieces;
}

/** Splits a document into line-aligned chunks, each prefixed with the document title for context. */
export function chunkDocument(doc: KnowledgeDocument, targetChars = LIMITS.chunkTargetChars): Chunk[] {
  const lines = doc.text.split(/\n+/).map((l) => l.trim()).filter(Boolean);
  const chunks: Chunk[] = [];
  let current: string[] = [];
  let size = 0;

  const flush = () => {
    if (current.length === 0) return;
    chunks.push({
      id: `${doc.id}#${chunks.length + 1}`,
      docId: doc.id,
      title: doc.title,
      url: doc.url,
      text: current.join('\n'),
    });
    current = [];
    size = 0;
  };

  for (const line of lines) {
    // Hard-wrap pathological single lines so no chunk grows unbounded and all content is preserved.
    const pieces = line.length > targetChars * 2 ? splitLongLine(line, targetChars) : [line];
    for (const piece of pieces) {
      if (size + piece.length > targetChars && size > 0) flush();
      current.push(piece.trim());
      size += piece.length + 1;
    }
  }
  flush();
  return chunks;
}

export class KnowledgeBaseStore {
  private docs = new Map<string, KnowledgeDocument>();

  constructor(private readonly allowedHosts: readonly string[]) {}

  get size(): number {
    return this.docs.size;
  }

  all(): KnowledgeDocument[] {
    return [...this.docs.values()].sort((a, b) => a.id.localeCompare(b.id));
  }

  get(id: string): KnowledgeDocument | undefined {
    return this.docs.get(id);
  }

  chunks(): Chunk[] {
    return this.all().flatMap((doc) => chunkDocument(doc));
  }

  /**
   * Replaces the whole store from raw data, validating every document.
   * Invalid documents are reported and skipped; a repeated id keeps the
   * first occurrence and reports the others, so a later entry can never
   * silently replace reviewed content.
   */
  loadFrom(data: unknown): { loaded: number; rejected: { index: number; field: string }[] } {
    const rejected: { index: number; field: string }[] = [];
    const next = new Map<string, KnowledgeDocument>();
    const documents = (data as { documents?: unknown })?.documents;
    if (!Array.isArray(documents)) {
      throw new DocumentValidationError('documents', 'Knowledge base file must contain a documents array.');
    }
    documents.forEach((raw, index) => {
      try {
        const doc = validateDocument(raw, this.allowedHosts);
        if (next.has(doc.id)) throw new DocumentValidationError('id', 'Duplicate document id.');
        next.set(doc.id, doc);
      } catch (err) {
        rejected.push({ index, field: err instanceof DocumentValidationError ? err.field : 'unknown' });
      }
    });
    this.docs = next;
    return { loaded: next.size, rejected };
  }

  /** Applies an index update. Returns counts; does not persist. */
  apply(update: { upserts: KnowledgeDocument[]; deleteIds: string[]; replaceAll: boolean }): {
    upserted: number;
    unchanged: number;
    deleted: number;
  } {
    const next = update.replaceAll ? new Map<string, KnowledgeDocument>() : new Map(this.docs);
    let upserted = 0;
    let unchanged = 0;
    let deleted = 0;

    for (const doc of update.upserts) {
      const existing = this.docs.get(doc.id);
      if (existing && documentFingerprint(existing) === documentFingerprint(doc)) unchanged += 1;
      else upserted += 1;
      next.set(doc.id, doc);
    }
    for (const id of update.deleteIds) {
      if (next.delete(id)) deleted += 1;
    }
    if (update.replaceAll) {
      for (const id of this.docs.keys()) if (!next.has(id)) deleted += 1;
    }
    if (next.size > LIMITS.maxDocuments) {
      throw new DocumentValidationError('documents', `Knowledge base may hold at most ${LIMITS.maxDocuments} documents.`);
    }
    this.docs = next;
    return { upserted, unchanged, deleted };
  }

  toFile(now = new Date()): KnowledgeBaseFile {
    return { version: 1, generated_at: now.toISOString(), documents: this.all() };
  }
}

const FALLBACK_TMP_PATH = join(tmpdir(), 'wkc-kb.json');

export async function readKnowledgeBaseFile(path: string): Promise<unknown> {
  if (path !== FALLBACK_TMP_PATH) {
    try {
      return JSON.parse(await readFile(FALLBACK_TMP_PATH, 'utf8'));
    } catch {
      // Fall through to requested path
    }
  }
  return JSON.parse(await readFile(path, 'utf8'));
}

async function writeAtomic(path: string, data: KnowledgeBaseFile): Promise<void> {
  await mkdir(dirname(path), { recursive: true });
  const tmp = `${path}.${process.pid}.${Date.now()}.tmp`;
  await writeFile(tmp, JSON.stringify(data, null, 2) + '\n', { encoding: 'utf8', mode: 0o640 });
  await rename(tmp, path);
}

/** Atomic write: a crash mid-write never leaves a truncated knowledge base. */
export async function writeKnowledgeBaseFile(path: string, data: KnowledgeBaseFile): Promise<void> {
  try {
    await writeAtomic(path, data);
  } catch (err) {
    if (err && typeof err === 'object' && (err as { code?: string }).code === 'EROFS') {
      await writeAtomic(FALLBACK_TMP_PATH, data);
      return;
    }
    throw err;
  }
}
