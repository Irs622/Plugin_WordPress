import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { htmlToText } from '../src/lib/html-to-text.js';
import {
  canonicalizeSourceUrl,
  chunkDocument,
  KnowledgeBaseStore,
  validateDocument,
} from '../src/services/knowledge-base.js';
import { Bm25Retriever } from '../src/services/retrieval.js';
import { tokenizeQuery } from '../src/services/text.js';
import { FIXTURE_DOCS } from './helpers.js';

const HOSTS = ['inpartnerglobal.com', 'www.inpartnerglobal.com'];
const valid = { ...FIXTURE_DOCS[0]! };

describe('canonicalizeSourceUrl', () => {
  it('accepts approved https URLs and strips fragments', () => {
    assert.equal(canonicalizeSourceUrl('https://inpartnerglobal.com/contact/#form', HOSTS), 'https://inpartnerglobal.com/contact/');
    assert.equal(canonicalizeSourceUrl('https://INPARTNERGLOBAL.com:443/a', HOSTS), 'https://inpartnerglobal.com/a');
  });

  it('rejects malicious or unapproved URLs', () => {
    for (const bad of [
      'http://inpartnerglobal.com/',
      'javascript:alert(1)',
      'data:text/html,<script>alert(1)</script>',
      'https://evil.com/',
      'https://inpartnerglobal.com.evil.com/',
      'https://user:pass@inpartnerglobal.com/',
      'https://inpartnerglobal.com:8443/',
      '//inpartnerglobal.com/',
      'not a url',
      42,
    ]) {
      assert.equal(canonicalizeSourceUrl(bad, HOSTS), null, String(bad));
    }
  });
});

describe('validateDocument', () => {
  it('accepts a valid published document', () => {
    assert.equal(validateDocument(valid, HOSTS).id, 'page-1-home');
  });

  it('rejects private, draft and otherwise unpublished content', () => {
    for (const status of ['draft', 'private', 'pending', 'future', 'trash', undefined]) {
      assert.throws(() => validateDocument({ ...valid, status }, HOSTS), /Only published content/);
    }
  });

  it('rejects invalid metadata', () => {
    assert.throws(() => validateDocument({ ...valid, id: '../etc/passwd' }, HOSTS), /id must match/);
    assert.throws(() => validateDocument({ ...valid, title: '' }, HOSTS), /title is required/);
    assert.throws(() => validateDocument({ ...valid, url: 'https://evil.com/' }, HOSTS), /approved host/);
    assert.throws(() => validateDocument({ ...valid, text: '   ' }, HOSTS), /text is required/);
    assert.throws(() => validateDocument({ ...valid, category: 'secret' }, HOSTS), /category must be/);
    assert.throws(() => validateDocument({ ...valid, updated_at: 'yesterday' }, HOSTS), /updated_at/);
    assert.throws(() => validateDocument(null, HOSTS), /must be an object/);
  });
});

describe('KnowledgeBaseStore', () => {
  it('is idempotent for unchanged documents and handles deletes', () => {
    const store = new KnowledgeBaseStore(HOSTS);
    const docs = FIXTURE_DOCS.map((d) => validateDocument(d, HOSTS));
    assert.deepEqual(store.apply({ upserts: docs, deleteIds: [], replaceAll: false }), { upserted: 3, unchanged: 0, deleted: 0 });
    assert.deepEqual(store.apply({ upserts: docs, deleteIds: [], replaceAll: false }), { upserted: 0, unchanged: 3, deleted: 0 });
    assert.deepEqual(store.apply({ upserts: [], deleteIds: ['page-3-contact', 'missing'], replaceAll: false }), { upserted: 0, unchanged: 0, deleted: 1 });
    assert.deepEqual(store.apply({ upserts: [docs[0]!], deleteIds: [], replaceAll: true }), { upserted: 0, unchanged: 1, deleted: 1 });
    assert.equal(store.size, 1);
  });

  it('skips invalid documents when loading from file', () => {
    const store = new KnowledgeBaseStore(HOSTS);
    const result = store.loadFrom({ documents: [valid, { ...valid, id: 'draft-1', status: 'draft' }] });
    assert.equal(result.loaded, 1);
    assert.deepEqual(result.rejected, [{ index: 1, field: 'status' }]);
  });
});

describe('chunkDocument', () => {
  it('splits long documents into bounded, line-aligned chunks', () => {
    const doc = validateDocument({ ...valid, text: Array.from({ length: 40 }, (_, i) => `Line ${i} ${'x'.repeat(50)}`).join('\n') }, HOSTS);
    const chunks = chunkDocument(doc, 300);
    assert.ok(chunks.length > 5);
    for (const c of chunks) {
      assert.ok(c.text.length <= 360, `chunk too long: ${c.text.length}`);
      assert.equal(c.url, doc.url);
    }
    assert.equal(chunks[0]!.id, 'page-1-home#1');
  });

  it('safely splits long unbroken lines without dropping content', () => {
    const text = 'START_' + 'a'.repeat(2000) + '_END';
    const doc = validateDocument({ ...valid, text }, HOSTS);
    const chunks = chunkDocument(doc, 300);
    assert.ok(chunks.length >= 7);
    const combined = chunks.map((c) => c.text).join('');
    assert.equal(combined, text);
  });
});

describe('Bm25Retriever', () => {
  const store = new KnowledgeBaseStore(HOSTS);
  store.loadFrom({ documents: FIXTURE_DOCS });
  const retriever = new Bm25Retriever(store.chunks());

  it('retrieves the relevant document', () => {
    assert.equal(retriever.search('How do you shortlist distributors and resellers?', 3, 1)[0]?.chunk.docId, 'page-2-distribution');
    assert.equal(retriever.search('What is your email?', 3, 1)[0]?.chunk.docId, 'page-3-contact');
  });

  it('maps Indonesian terms onto English content', () => {
    assert.ok(tokenizeQuery('mitra distribusi').includes('partner'));
    assert.equal(retriever.search('Bagaimana mencari mitra distribusi?', 3, 1)[0]?.chunk.docId, 'page-2-distribution');
  });

  it('returns nothing for unrelated or empty questions', () => {
    assert.deepEqual(retriever.search('weather forecast tomorrow', 3, 1), []);
    assert.deepEqual(retriever.search('the and of', 3, 1), []);
    assert.deepEqual(retriever.search('', 3, 1), []);
  });
});

describe('htmlToText', () => {
  it('strips markup, scripts, navigation and duplicate lines', () => {
    const html = `<nav>Menu</nav><h2>Due<br>Diligence</h2><p onclick="x()">Assess &amp; review</p>
      <script>alert(1)</script><style>p{}</style><p>Assess &amp; review</p><p>Lear More</p><footer>Copyright</footer>`;
    assert.equal(htmlToText(html, { excludePatterns: [/^lear?n? more$/i] }), 'Due Diligence\nAssess & review');
  });

  it('decodes numeric entities safely', () => {
    assert.equal(htmlToText('<p>M&#038;A &#x2014; ok &#0; &bogus;</p>'), 'M&A — ok &bogus;');
  });
});
