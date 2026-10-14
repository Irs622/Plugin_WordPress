/**
 * Converts rendered WordPress/Elementor HTML into clean line-oriented text.
 * Dependency-free and intentionally conservative: it removes markup,
 * scripts, styles and navigation, decodes entities and drops boilerplate.
 */

const NAMED_ENTITIES: Record<string, string> = {
  amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', ndash: '–', mdash: '—',
  hellip: '…', rsquo: '’', lsquo: '‘', rdquo: '”', ldquo: '“', copy: '©', reg: '®', trade: '™',
};

export function decodeEntities(text: string): string {
  return text.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (match, entity: string) => {
    if (entity[0] === '#') {
      const code = entity[1]?.toLowerCase() === 'x' ? parseInt(entity.slice(2), 16) : parseInt(entity.slice(1), 10);
      return Number.isFinite(code) && code > 0 && code <= 0x10ffff ? String.fromCodePoint(code) : '';
    }
    return NAMED_ENTITIES[entity.toLowerCase()] ?? match;
  });
}

export interface CleanOptions {
  /** Lines matching any of these (case-insensitive) are removed. */
  excludePatterns?: RegExp[];
}

export function htmlToText(html: string, options: CleanOptions = {}): string {
  let text = html
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/<(script|style|svg|noscript|template|iframe|form|nav|header|footer)\b[\s\S]*?<\/\1\s*>/gi, ' ')
    .replace(/<br\s*\/?>/gi, ' ')
    .replace(/<\/(p|h[1-6]|li|div|section|article|tr|blockquote|figcaption|dt|dd)\s*>/gi, '\n')
    .replace(/<[^>]+>/g, ' ');
  text = decodeEntities(text).replace(/[\t ​ ]+/g, ' ');

  const seen = new Set<string>();
  const lines: string[] = [];
  for (const rawLine of text.split('\n')) {
    const line = rawLine.trim();
    if (line.length < 2) continue;
    if (options.excludePatterns?.some((re) => re.test(line))) continue;
    // Elementor often renders the same card twice (desktop/mobile variants).
    const key = line.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);
    lines.push(line);
  }
  return lines.join('\n');
}
