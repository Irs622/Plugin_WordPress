/**
 * Tokenisation for lexical retrieval. The knowledge base is English; small
 * curated Indonesian->English and Korean->English term maps let Indonesian
 * and Korean questions reach the same chunks. This is a pragmatic MVP
 * measure, not general translation.
 */

const STOPWORDS = new Set(
  (
    // English
    'a an and are as at be been but by can could do does did for from had has have how i if in into is it its ' +
    'me my of on or our please should so tell than that the their them then there these they this those to ' +
    'us was we what when where which will with would you your yours also any some more most ' +
    // Indonesian
    'yang dan di ke dari apa apakah bagaimana gimana saya aku kami kita anda kamu untuk dengan ini itu ada bisa ' +
    'dapat adalah ya tidak atau juga akan sudah pada oleh mana berapa kapan tolong mohon jelaskan sebutkan ' +
    'seperti para tersebut lebih sangat punya memiliki menawarkan tawarkan'
  ).split(/\s+/),
);

/** Indonesian (and colloquial English) terms mapped to English stems present in the corpus. */
const QUERY_EXPANSIONS: Record<string, string[]> = {
  // Indonesian
  layanan: ['service', 'advisory'],
  jasa: ['service', 'advisory'],
  servis: ['service', 'advisory'],
  mitra: ['partner'],
  kemitraan: ['partner', 'partnership'],
  distribusi: ['distribution', 'distributor'],
  pasar: ['market'],
  masuk: ['entry', 'enter'],
  memasuki: ['entry', 'enter'],
  ekspansi: ['expansion', 'expand'],
  perluasan: ['expansion', 'expand'],
  berkembang: ['growth', 'expand'],
  pertumbuhan: ['growth'],
  kontak: ['contact'],
  hubungi: ['contact'],
  menghubungi: ['contact'],
  alamat: ['address', 'tower', 'jakarta'],
  kantor: ['office', 'tower', 'jakarta'],
  lokasi: ['location', 'tower', 'jakarta'],
  telepon: ['call', 'phone'],
  nomor: ['call', 'phone'],
  surel: ['email'],
  tentang: ['about'],
  profil: ['about'],
  perusahaan: ['company'],
  akuisisi: ['acquisition', 'cross', 'border', 'deal', 'transaction'],
  penggabungan: ['merger', 'cross', 'border', 'deal', 'transaction'],
  merger: ['merger', 'cross', 'border', 'deal', 'transaction'],
  investasi: ['investment', 'investor'],
  strategi: ['strategy', 'strategic'],
  operasional: ['operation', 'operational'],
  operasi: ['operation', 'operational'],
  konsultasi: ['advisory', 'consultation'],
  konsultan: ['advisory', 'advisor'],
  penasihat: ['advisory', 'advisor'],
  lintas: ['cross', 'border'],
  internasional: ['international', 'cross', 'border'],
  negosiasi: ['negotiation'],
  tenggara: ['southeast'],
  asing: ['foreign'],
  pendekatan: ['approach'],
  proses: ['approach', 'process'],
  langkah: ['approach', 'step'],
  siapa: ['who', 'about'],
  // English paraphrases
  service: ['service', 'advisory'],
  offer: ['service', 'advisory'],
  provide: ['service', 'advisory'],
  do: ['advisory'],
  step: ['approach'],
  email: ['email', 'contact'],
  consult: ['advisory'],
  consulting: ['advisory'],
  consultant: ['advisory', 'advisor'],
  consultancy: ['advisory'],
  reach: ['contact'],
  office: ['tower', 'jakarta', 'address'],
  located: ['tower', 'jakarta', 'address'],
  location: ['tower', 'jakarta', 'address'],
  phone: ['call', 'phone'],
  telephone: ['call', 'phone'],
  mail: ['email'],
  acquisition: ['acquisition', 'cross', 'border', 'deal', 'transaction'],
  acquire: ['acquisition', 'cross', 'border', 'deal', 'transaction'],
  distributor: ['distributor', 'distribution'],
  distribution: ['distributor', 'distribution'],
  reseller: ['distributor', 'reseller'],
  expand: ['expansion', 'expand', 'market'],
  expansion: ['expansion', 'expand', 'market'],
  enter: ['entry', 'enter', 'market'],
  entering: ['entry', 'enter', 'market'],
  process: ['approach'],
  methodology: ['approach'],
  method: ['approach'],
  who: ['about'],
  team: ['about'],
  firm: ['about', 'advisory'],
  indonesian: ['indonesia'],
};

/**
 * Korean terms mapped to English stems present in the corpus. Korean attaches
 * particles to words (서비스는, 시장에), so keys are matched as substrings of
 * the NFC-normalised question rather than as whole tokens.
 */
const KOREAN_EXPANSIONS: [string, string[]][] = [
  ['인파트너', ['inpartner']],
  ['서비스', ['service', 'advisory']],
  ['제공', ['service', 'advisory']],
  ['자문', ['advisory', 'advisor']],
  ['컨설팅', ['advisory', 'consultation']],
  ['컨설턴트', ['advisory', 'advisor']],
  ['상담', ['advisory', 'consultation']],
  ['파트너십', ['partner', 'partnership']],
  ['파트너', ['partner']],
  ['협력사', ['partner']],
  ['제휴', ['partner', 'partnership']],
  ['유통', ['distribution', 'distributor']],
  ['총판', ['distribution', 'distributor']],
  ['대리점', ['distributor', 'reseller']],
  ['리셀러', ['reseller', 'distributor']],
  ['시장', ['market']],
  ['진출', ['entry', 'enter', 'market']],
  ['진입', ['entry', 'enter', 'market']],
  ['확장', ['expansion', 'expand']],
  ['확대', ['expansion', 'expand']],
  ['성장', ['growth']],
  ['연락', ['contact']],
  ['문의', ['contact']],
  ['주소', ['address', 'tower', 'jakarta']],
  ['사무실', ['office', 'tower', 'jakarta']],
  ['사무소', ['office', 'tower', 'jakarta']],
  ['위치', ['location', 'tower', 'jakarta']],
  ['이메일', ['email']],
  ['메일', ['email']],
  ['전화', ['call', 'phone']],
  ['회사', ['about', 'company']],
  ['기업', ['company']],
  ['소개', ['about']],
  ['누구', ['who', 'about']],
  ['인수', ['acquisition', 'cross', 'border', 'deal', 'transaction']],
  ['합병', ['merger', 'cross', 'border', 'deal', 'transaction']],
  ['투자', ['investment', 'investor']],
  ['전략', ['strategy', 'strategic']],
  ['운영', ['operation', 'operational']],
  ['실사', ['due', 'diligence']],
  ['협상', ['negotiation']],
  ['평가', ['assessment', 'assess', 'evaluation', 'evaluate']],
  ['동남아', ['southeast', 'asia']],
  ['해외', ['international', 'cross', 'border', 'foreign']],
  ['국경', ['cross', 'border']],
  ['국제', ['international', 'cross', 'border']],
  ['외국', ['foreign']],
  ['인도네시아', ['indonesia']],
  ['접근', ['approach']],
  ['절차', ['approach', 'process']],
  ['과정', ['approach', 'process']],
  ['단계', ['approach', 'step']],
];

/** Very light English stemmer: plural/verb suffixes only, enough to merge common variants. */
export function stem(word: string): string {
  if (word.length <= 3) return word;
  if (word.endsWith('ies') && word.length > 4) return word.slice(0, -3) + 'y';
  if (/(ss|us|is)$/.test(word)) return word;
  if (/(ches|shes|xes|sses)$/.test(word)) return word.slice(0, -2);
  if (word.endsWith('s')) return word.slice(0, -1);
  if (word.endsWith('ing') && word.length > 5) return word.slice(0, -3);
  if (word.endsWith('ed') && word.length > 4) return word.slice(0, -2);
  return word;
}

export function normalizeText(text: string): string {
  return text
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/\bm\s*&\s*a\b/g, ' merger acquisition ')
    .replace(/&amp;/g, ' and ');
}

export function tokenize(text: string): string[] {
  const tokens: string[] = [];
  for (const raw of normalizeText(text).split(/[^a-z0-9]+/)) {
    if (raw.length < 2 || STOPWORDS.has(raw)) continue;
    tokens.push(stem(raw));
  }
  return tokens;
}

/**
 * Tokenises a query and adds mapped expansion terms (deduplicated). Markup
 * is removed first so tag and attribute names in a pasted payload (for
 * example `<source id="S9">`) are not treated as search terms.
 */
export function tokenizeQuery(text: string): string[] {
  const out = new Set<string>();
  const plain = text.replace(/<[^>]*>/g, ' ');
  // Before normalizeText(): NFKD splits Hangul syllables into jamo.
  const nfc = plain.normalize('NFC');
  for (const [term, expansions] of KOREAN_EXPANSIONS) {
    if (nfc.includes(term)) for (const e of expansions) out.add(stem(e));
  }
  for (const raw of normalizeText(plain).split(/[^a-z0-9]+/)) {
    if (raw.length < 2) continue;
    const expansions = QUERY_EXPANSIONS[raw] ?? QUERY_EXPANSIONS[stem(raw)];
    if (expansions) for (const e of expansions) out.add(stem(e));
    if (!STOPWORDS.has(raw)) out.add(stem(raw));
  }
  return [...out];
}
