import type { Chunk } from './knowledge-base.js';
import { tokenize, tokenizeQuery } from './text.js';

/**
 * BM25 lexical retrieval over knowledge-base chunks. Embeddings are not
 * used in the MVP: the corpus is a handful of pages and lexical search is
 * evaluated in eval/ before anything heavier is justified.
 */

export interface RetrievedChunk {
  chunk: Chunk;
  score: number;
}

const K1 = 1.2;
const B = 0.75;
/** Title terms count extra so page-level topics outrank incidental mentions. */
const TITLE_WEIGHT = 2;

interface IndexedChunk {
  chunk: Chunk;
  termFreq: Map<string, number>;
  length: number;
}

export class Bm25Retriever {
  private indexed: IndexedChunk[] = [];
  private docFreq = new Map<string, number>();
  private avgLength = 0;

  constructor(chunks: Chunk[] = []) {
    this.rebuild(chunks);
  }

  get chunkCount(): number {
    return this.indexed.length;
  }

  rebuild(chunks: Chunk[]): void {
    this.docFreq = new Map();
    this.indexed = chunks.map((chunk) => {
      const tokens = tokenize(chunk.text);
      for (let i = 0; i < TITLE_WEIGHT; i++) tokens.push(...tokenize(chunk.title));
      const termFreq = new Map<string, number>();
      for (const t of tokens) termFreq.set(t, (termFreq.get(t) ?? 0) + 1);
      for (const t of termFreq.keys()) this.docFreq.set(t, (this.docFreq.get(t) ?? 0) + 1);
      return { chunk, termFreq, length: tokens.length };
    });
    const total = this.indexed.reduce((sum, c) => sum + c.length, 0);
    this.avgLength = this.indexed.length ? total / this.indexed.length : 0;
  }

  search(query: string, topK: number, minScore: number): RetrievedChunk[] {
    const terms = tokenizeQuery(query);
    if (terms.length === 0 || this.indexed.length === 0) return [];
    const n = this.indexed.length;

    const scored: RetrievedChunk[] = [];
    for (const item of this.indexed) {
      let score = 0;
      for (const term of terms) {
        const tf = item.termFreq.get(term);
        if (!tf) continue;
        const df = this.docFreq.get(term) ?? 0;
        const idf = Math.log(1 + (n - df + 0.5) / (df + 0.5));
        score += (idf * tf * (K1 + 1)) / (tf + K1 * (1 - B + (B * item.length) / this.avgLength));
      }
      if (score >= minScore) scored.push({ chunk: item.chunk, score });
    }
    scored.sort((a, b) => b.score - a.score || a.chunk.id.localeCompare(b.chunk.id));
    return scored.slice(0, topK);
  }
}
