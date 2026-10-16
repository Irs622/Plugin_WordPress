import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { describe, it } from 'node:test';
import { auditKnowledgeBase, parseSourcesFile, type SourcesFile } from '../src/services/kb-audit.js';
import { KnowledgeBaseStore } from '../src/services/knowledge-base.js';
import { FIXTURE_DOCS } from './helpers.js';

const HOSTS = ['inpartnerglobal.com', 'www.inpartnerglobal.com'];

const SOURCES: SourcesFile = {
  site: 'https://inpartnerglobal.com',
  global_exclude_patterns: ['^read more$', '^\\+?[\\d\\s().-]{9,}$'],
  documents: [
    { id: 'page-1-home', wp_id: 1, category: 'home' },
    { id: 'page-2-distribution', wp_id: 2, category: 'service' },
    { id: 'page-3-contact', wp_id: 3, category: 'contact', exclude_patterns: ['^call us$'] },
    { id: 'page-4-ma', wp_id: 4, category: 'service', optional: true },
  ],
};

const kb = (docs: unknown[]) => ({ version: 1, generated_at: '2026-10-14T00:00:00Z', documents: docs });

describe('auditKnowledgeBase', () => {
  it('accepts a snapshot of approved pages and only warns about a missing optional page', () => {
    assert.deepEqual(auditKnowledgeBase(kb(FIXTURE_DOCS), SOURCES, HOSTS), {
      errors: [],
      warnings: ['page-4-ma: optional page not in the knowledge base'],
    });
  });

  it('rejects stale or unapproved documents', () => {
    const stale = { ...FIXTURE_DOCS[0]!, id: 'page-99-old-campaign' };
    const { errors } = auditKnowledgeBase(kb([...FIXTURE_DOCS, stale]), SOURCES, HOSTS);
    assert.deepEqual(errors, ['page-99-old-campaign: not in the approved source list (stale, deleted or unapproved page)']);
  });

  it('rejects a snapshot missing a required page (deleted page or partial build)', () => {
    const { errors } = auditKnowledgeBase(kb(FIXTURE_DOCS.slice(0, 2)), SOURCES, HOSTS);
    assert.deepEqual(errors, ['page-3-contact: approved page missing from the knowledge base']);
  });

  it('rejects duplicate ids, empty content, category mismatches and excluded lines', () => {
    const [home, dist, contact] = FIXTURE_DOCS as [typeof FIXTURE_DOCS[0], typeof FIXTURE_DOCS[0], typeof FIXTURE_DOCS[0]];
    const { errors } = auditKnowledgeBase(
      kb([
        home,
        { ...home, text: 'A different home text that is long enough to pass.' },
        { ...dist, text: 'Too short', category: 'faq' },
        { ...contact, text: `${contact.text}\nCall Us\n+62 896 2831 0192` },
      ]),
      SOURCES,
      HOSTS,
    );
    assert.deepEqual(errors, [
      'page-1-home: duplicate id',
      'page-2-distribution: category "faq" does not match sources.json ("service")',
      'page-2-distribution: only 9 characters of text (empty or incomplete page)',
      'page-3-contact: contains 2 line(s) matching exclude patterns',
    ]);
  });

  it('rejects invalid and unpublished documents', () => {
    const { errors } = auditKnowledgeBase(kb([...FIXTURE_DOCS, { ...FIXTURE_DOCS[0]!, id: 'page-5-x', status: 'draft' }]), SOURCES, HOSTS);
    assert.deepEqual(errors, ['documents[3]: invalid status']);
  });

  it('warns when a rebuilt page loses most of its text', () => {
    const previous = kb(FIXTURE_DOCS.map((d) => ({ ...d, text: d.text.repeat(3) })));
    const { errors, warnings } = auditKnowledgeBase(kb(FIXTURE_DOCS), SOURCES, HOSTS, previous);
    assert.deepEqual(errors, []);
    assert.equal(warnings.filter((w) => w.includes('text shrank')).length, 3);
  });

  it('passes for the committed data/kb.json and data/sources.json', async () => {
    const sources = parseSourcesFile(JSON.parse(await readFile('data/sources.json', 'utf8')));
    const { errors } = auditKnowledgeBase(JSON.parse(await readFile('data/kb.json', 'utf8')), sources, HOSTS);
    assert.deepEqual(errors, []);
  });

  it('keeps disputed phone numbers out and verifies authoritative phone in knowledge base', async () => {
    const text = await readFile('data/kb.json', 'utf8');
    assert.ok(!/896 2831 0192|555-0120/.test(text));
    assert.ok(text.includes('+62 859 3454 8202'));
  });
});

describe('parseSourcesFile', () => {
  it('rejects duplicate ids and WordPress ids, bad categories and invalid patterns', () => {
    assert.throws(
      () =>
        parseSourcesFile({
          site: 'https://inpartnerglobal.com',
          global_exclude_patterns: ['('],
          documents: [
            { id: 'a', wp_id: 1, category: 'home' },
            { id: 'a', wp_id: 1, category: 'blog' },
          ],
        }),
      (err: Error) =>
        /global_exclude_patterns\[0\]: invalid regular expression/.test(err.message) &&
        /duplicate id a/.test(err.message) &&
        /duplicate wp_id 1/.test(err.message) &&
        /invalid category/.test(err.message),
    );
    assert.throws(() => parseSourcesFile({ site: 'http://inpartnerglobal.com', global_exclude_patterns: [], documents: [{ id: 'a', wp_id: 1, category: 'home' }] }), /https/);
  });

  it('accepts a complete profile entry and rejects incomplete or unsafe ones', () => {
    const profile = { id: 'a', wp_id: 1, category: 'home', origin: 'profile', profile_file: 'profile/home.txt', title: 'Home', url: 'https://inpartnerglobal.com/', updated_at: '2026-10-16T00:00:00Z' };
    const file = (entry: object) => ({ site: 'https://inpartnerglobal.com', global_exclude_patterns: [], documents: [entry] });
    assert.doesNotThrow(() => parseSourcesFile(file(profile)));
    assert.throws(() => parseSourcesFile(file({ ...profile, profile_file: '../secrets.txt' })), /profile_file/);
    assert.throws(() => parseSourcesFile(file({ ...profile, profile_file: undefined })), /profile_file/);
    assert.throws(() => parseSourcesFile(file({ ...profile, url: 'http://inpartnerglobal.com/' })), /https url|url must be https/);
    assert.throws(() => parseSourcesFile(file({ ...profile, title: ' ' })), /title/);
    assert.throws(() => parseSourcesFile(file({ ...profile, updated_at: 'yesterday' })), /updated_at/);
    assert.throws(() => parseSourcesFile(file({ ...profile, origin: 'import' })), /origin/);
  });

  it('marks every committed document as owner-approved profile text with the correct vision', async () => {
    const sources = parseSourcesFile(JSON.parse(await readFile('data/sources.json', 'utf8')));
    assert.ok(sources.documents.every((e) => e.origin === 'profile'));
    const kbText = await readFile('data/kb.json', 'utf8');
    assert.ok(kbText.includes('The Most Trusted Consulting Partner To help create positive and endure changes in Local and Global Coverage.'));
  });
});

describe('KnowledgeBaseStore.loadFrom', () => {
  it('keeps the first of duplicate ids and reports the rest', () => {
    const store = new KnowledgeBaseStore(HOSTS);
    const result = store.loadFrom(kb([FIXTURE_DOCS[0], { ...FIXTURE_DOCS[0]!, text: 'Replacement text that must not win.' }]));
    assert.equal(result.loaded, 1);
    assert.deepEqual(result.rejected, [{ index: 1, field: 'id' }]);
    assert.equal(store.get('page-1-home')?.text, FIXTURE_DOCS[0]!.text);
  });
});
