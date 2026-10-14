import { DOCUMENT_CATEGORIES, DocumentValidationError, validateDocument } from './knowledge-base.js';

/**
 * Checks that a knowledge-base snapshot matches the approved source list
 * (data/sources.json). Used by `kb:build` before writing and by
 * `kb:validate` in CI, so the shipped kb.json can only contain approved,
 * non-empty pages with excluded lines removed.
 */

/** Pages with less body text than this are treated as empty or failed extractions. */
export const MIN_DOCUMENT_CHARS = 40;

/** A rebuilt page that loses more than this share of its text is flagged for review. */
export const SHRINK_WARNING_RATIO = 0.5;

export interface SourceEntry {
  id: string;
  wp_id: number;
  category: string;
  exclude_patterns?: string[];
  /** The page may be missing from the snapshot (for example, published without body text yet). */
  optional?: boolean;
}

export interface SourcesFile {
  site: string;
  global_exclude_patterns: string[];
  documents: SourceEntry[];
}

export interface AuditResult {
  errors: string[];
  warnings: string[];
}

function compile(pattern: unknown, where: string, problems: string[]): RegExp | null {
  if (typeof pattern !== 'string' || pattern === '') {
    problems.push(`${where}: pattern must be a non-empty string`);
    return null;
  }
  try {
    return new RegExp(pattern, 'i');
  } catch {
    problems.push(`${where}: invalid regular expression`);
    return null;
  }
}

/** Validates sources.json and returns it typed; throws with every problem listed. */
export function parseSourcesFile(data: unknown): SourcesFile {
  const problems: string[] = [];
  const file = data as Partial<SourcesFile> | null;
  if (typeof file !== 'object' || file === null) throw new Error('sources file must be a JSON object');

  try {
    if (new URL(String(file.site)).protocol !== 'https:') problems.push('site must be an https URL');
  } catch {
    problems.push('site must be an https URL');
  }
  if (!Array.isArray(file.global_exclude_patterns)) problems.push('global_exclude_patterns must be an array');
  else file.global_exclude_patterns.forEach((p, i) => compile(p, `global_exclude_patterns[${i}]`, problems));

  if (!Array.isArray(file.documents) || file.documents.length === 0) {
    problems.push('documents must be a non-empty array');
  } else {
    const ids = new Set<string>();
    const wpIds = new Set<number>();
    file.documents.forEach((entry, i) => {
      const where = `documents[${i}]`;
      if (typeof entry?.id !== 'string' || !/^[a-z0-9][a-z0-9._:-]{0,127}$/.test(entry.id)) problems.push(`${where}: invalid id`);
      else if (ids.has(entry.id)) problems.push(`${where}: duplicate id ${entry.id}`);
      else ids.add(entry.id);
      if (!Number.isInteger(entry?.wp_id) || entry.wp_id <= 0) problems.push(`${where}: wp_id must be a positive integer`);
      else if (wpIds.has(entry.wp_id)) problems.push(`${where}: duplicate wp_id ${entry.wp_id}`);
      else wpIds.add(entry.wp_id);
      if (!(DOCUMENT_CATEGORIES as readonly string[]).includes(entry?.category)) problems.push(`${where}: invalid category`);
      if (entry?.optional !== undefined && typeof entry.optional !== 'boolean') problems.push(`${where}: optional must be a boolean`);
      (entry?.exclude_patterns ?? []).forEach((p, j) => compile(p, `${where}.exclude_patterns[${j}]`, problems));
    });
  }
  if (problems.length > 0) throw new Error(`Invalid sources file:\n  ${problems.join('\n  ')}`);
  return file as SourcesFile;
}

/** Exclusion patterns that apply to one source page (global + page-specific). */
export function excludePatternsFor(sources: SourcesFile, entry: SourceEntry): RegExp[] {
  return [...sources.global_exclude_patterns, ...(entry.exclude_patterns ?? [])].map((p) => new RegExp(p, 'i'));
}

/**
 * Audits a knowledge-base file against the approved sources. `previous`
 * (the snapshot being replaced, if any) enables shrinkage warnings.
 */
export function auditKnowledgeBase(kb: unknown, sources: SourcesFile, allowedHosts: readonly string[], previous?: unknown): AuditResult {
  const errors: string[] = [];
  const warnings: string[] = [];
  const documents = (kb as { documents?: unknown })?.documents;
  if (!Array.isArray(documents)) return { errors: ['knowledge base must contain a documents array'], warnings };
  if (documents.length === 0) errors.push('knowledge base is empty');

  const approved = new Map(sources.documents.map((e) => [e.id, e]));
  const seen = new Set<string>();
  const previousText = new Map<string, string>();
  for (const doc of ((previous as { documents?: unknown })?.documents ?? []) as { id?: unknown; text?: unknown }[]) {
    if (typeof doc?.id === 'string' && typeof doc.text === 'string') previousText.set(doc.id, doc.text);
  }

  documents.forEach((raw, index) => {
    let doc;
    try {
      doc = validateDocument(raw, allowedHosts);
    } catch (err) {
      errors.push(`documents[${index}]: invalid ${err instanceof DocumentValidationError ? err.field : 'document'}`);
      return;
    }
    if (seen.has(doc.id)) {
      errors.push(`${doc.id}: duplicate id`);
      return;
    }
    seen.add(doc.id);

    const entry = approved.get(doc.id);
    if (!entry) {
      errors.push(`${doc.id}: not in the approved source list (stale, deleted or unapproved page)`);
      return;
    }
    if (doc.category !== entry.category) errors.push(`${doc.id}: category "${doc.category}" does not match sources.json ("${entry.category}")`);
    if (doc.text.length < MIN_DOCUMENT_CHARS) errors.push(`${doc.id}: only ${doc.text.length} characters of text (empty or incomplete page)`);

    const patterns = excludePatternsFor(sources, entry);
    const excludedLines = doc.text.split('\n').filter((line) => patterns.some((re) => re.test(line.trim()))).length;
    if (excludedLines > 0) errors.push(`${doc.id}: contains ${excludedLines} line(s) matching exclude patterns`);

    const before = previousText.get(doc.id);
    if (before && doc.text.length < before.length * (1 - SHRINK_WARNING_RATIO)) {
      warnings.push(`${doc.id}: text shrank from ${before.length} to ${doc.text.length} characters; review the page before deploying`);
    }
  });

  for (const entry of sources.documents) {
    if (seen.has(entry.id)) continue;
    if (entry.optional) warnings.push(`${entry.id}: optional page not in the knowledge base`);
    else errors.push(`${entry.id}: approved page missing from the knowledge base`);
  }
  return { errors, warnings };
}
