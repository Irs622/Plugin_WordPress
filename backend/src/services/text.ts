/**
 * Tokenisation for lexical retrieval. The knowledge base is English; a small
 * curated Indonesian->English term map lets Indonesian questions reach the
 * same chunks. This is a pragmatic MVP measure, not general translation.
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
};

/** Very light English stemmer: plural/verb suffixes only, enough to merge common variants. */
export function stem(token: string): string {
  if (token.length <= 3) return token;
  if (token.endsWith('ies') && token.length > 4) return token.slice(0, -3) + 'y';
  if (/(ss|us|is)$/.test(token)) return token;
  if (/(ches|shes|xes|sses)$/.test(token)) return token.slice(0, -2);
  if (token.endsWith('s')) return token.slice(0, -1);
  if (token.endsWith('ing') && token.length > 5) return token.slice(0, -3);
  if (token.endsWith('ed') && token.length > 4) return token.slice(0, -2);
  return token;
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

/** Tokenises a query and adds mapped expansion terms (deduplicated). */
export function tokenizeQuery(text: string): string[] {
  const out = new Set<string>();
  for (const raw of normalizeText(text).split(/[^a-z0-9]+/)) {
    if (raw.length < 2) continue;
    const expansions = QUERY_EXPANSIONS[raw] ?? QUERY_EXPANSIONS[stem(raw)];
    if (expansions) for (const e of expansions) out.add(stem(e));
    if (!STOPWORDS.has(raw)) out.add(stem(raw));
  }
  return [...out];
}
