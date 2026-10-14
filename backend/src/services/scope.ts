/**
 * Deterministic answerability checks around the LLM call.
 *
 * Lexical retrieval measures keyword overlap, not whether the evidence can
 * answer the question: "What is the weather in Jakarta?" matches the
 * office address, and "Who are your clients?" matches "Client Partnership".
 * Three checks close that gap without calling the model:
 *
 * 1. Scope policy (before retrieval): requests the assistant must never
 *    handle, whatever the knowledge base says: prompt injection, personal
 *    investment/legal/tax/medical advice, guarantees and unrelated tasks.
 * 2. Answer-type evidence (after retrieval): questions that ask for a
 *    specific kind of fact (prices, founding date, headcount, statistics,
 *    client names, office hours) reach the model only when the retrieved
 *    evidence contains that kind of fact. Publishing such content later
 *    opens the gate automatically.
 * 3. Claim verification (after generation): numbers, URLs, e-mail addresses
 *    and phone numbers in an answer must appear in the evidence.
 *
 * Patterns are written per category (English and Indonesian). Review them
 * when the knowledge base gains content in one of these areas.
 */

export type ScopeViolation = 'injection' | 'investment_advice' | 'legal_tax_advice' | 'medical' | 'guarantee' | 'unrelated';

const SCOPE_RULES: { kind: ScopeViolation; patterns: RegExp[] }[] = [
  {
    kind: 'injection',
    patterns: [
      /\b(ignore|disregard|forget|override|bypass)\b[^.?!]{0,40}\b(instructions?|rules?|prompts?|guidelines?|polic(y|ies))\b/i,
      /\b(system|hidden|internal|developer|initial)\s+(prompts?|instructions?|messages?|configuration|config|rules?)\b/i,
      /\b(reveal|show|print|repeat|display|output|tell me|what are)\b[^.?!]{0,30}\b(your|the system|hidden|internal)\s+(prompts?|instructions|rules|guidelines|configuration)\b/i,
      /\b(api[\s_-]?keys?|secret keys?|access tokens?|passwords?)\b/i,
      /\brepeat\b[^.?!]{0,30}\b(text|everything|words?)\b[^.?!]{0,20}\b(above|before|earlier)\b/i,
      /\byou are now\b|\bpretend (to be|you are|you're)\b|\bact as (if you|an? (human|person|different|unrestricted|new))\b|\bjailbreak\b|\bdeveloper mode\b/i,
      // Case-sensitive: lower-case "dan" is the Indonesian word for "and".
      /\bDAN\b/,
      /\bkunci api\b/i,
      /<\/?\s*(source|sources|question|system|instructions?|conversation_context)\b/i,
      /(^|\n)\s*(system|assistant|developer)\s*:/i,
      /\bgrounded\s*[=:]\s*(true|false)\b|\bsource_ids\b/i,
      /\b(unpublished|draft|private|hidden)\s+(pages?|posts?|content|documents?)\b/i,
      /\b(abaikan|lupakan|langgar)\b[^.?!]{0,40}\b(instruksi|aturan|perintah)\b/i,
      /\b(tampilkan|tunjukkan|sebutkan)\b[^.?!]{0,40}\b(instruksi|prompt|kunci|rahasia|konfigurasi)\b/i,
    ],
  },
  {
    kind: 'investment_advice',
    patterns: [
      /\bshould (i|we)\b[^.?!]{0,20}\b(buy|sell|invest)\b/i,
      /\b(which|what)\s+(stocks?|shares?|bonds?|crypto(currenc(y|ies))?|coins?|funds?)\b[^.?!]{0,30}\b(buy|sell|invest|recommend|pick)\b/i,
      /\b(buy|sell|invest in|trade)\b[^.?!]{0,30}\b(stocks?|shares?|bonds?|crypto(currenc(y|ies))?|bitcoin|forex)\b/i,
      /\b(stock|share|crypto)\s+(tips?|picks?|recommendations?|price targets?)\b/i,
      /\b(returns?|profits?|yields?)\s+on\s+(my|our|an?)\s+investments?\b/i,
      /\b(rupiah|idr|dollar|usd|currency|exchange rate|forex|stock market|ihsg)\b[^.?!]{0,40}\b(forecast|predict|weaken|strengthen|go up|go down|rise|fall|crash)\b/i,
      /\b(will|forecast|predict)\b[^.?!]{0,40}\b(rupiah|exchange rate|currency|stock price|stock market|ihsg)\b/i,
      /\bsaham\b|\breksa ?dana\b|\b(beli|membeli|jual|menjual)\b[^.?!]{0,20}\b(obligasi|kripto|emas)\b/i,
      /\binvestasi (apa|yang (bagus|terbaik|menguntungkan))\b|\bkurs\b[^.?!]{0,30}\b(naik|turun|melemah|menguat)\b/i,
    ],
  },
  {
    kind: 'legal_tax_advice',
    patterns: [
      /\b(tax(es|ation)?|pajak|pph|ppn)\b/i,
      /\b(legal advice|lawyer|attorney|lawsuit|litigation|is it legal|legally)\b/i,
      /\b(register|incorporate|set up|form)\b[^.?!]{0,30}\b(a |an |my |our )?(company|pt|entity|legal entity|subsidiary)\b/i,
      /\bpt\s?pma\b|\bkbli\b|\boss[\s-]?rba\b/i,
      /\b(mendirikan|mendaftarkan)\b[^.?!]{0,20}\b(pt|perusahaan|badan usaha)\b/i,
    ],
  },
  {
    kind: 'medical',
    patterns: [/\b(medicine|medication|doctor|symptoms?|diagnos\w*|disease|illness|prescription|obat|dokter|gejala|penyakit)\b/i],
  },
  {
    kind: 'guarantee',
    patterns: [/\b(guarantee[sd]?|promise|assure)\b/i, /\b(jamin|menjamin|dijamin|garansi)\b/i],
  },
  {
    kind: 'unrelated',
    patterns: [
      /\b(weather|cuaca|recipe|resep|joke|lelucon|poem|puisi|lyrics|horoscope|zodiac)\b/i,
      /\b(write|draft|compose|generate|create)\b[^.?!]{0,20}\b(poem|essay|story|code|script|business plan|cover letter|resume|cv|article)\b/i,
      /\b(buatkan|tuliskan|tulis)\b[^.?!]{0,20}\b(puisi|esai|cerita|kode|rencana bisnis|surat)\b/i,
    ],
  },
];

/** Returns the first scope violation found in a visitor question, or null. */
export function scopeViolation(question: string): ScopeViolation | null {
  for (const rule of SCOPE_RULES) {
    if (rule.patterns.some((re) => re.test(question))) return rule.kind;
  }
  return null;
}

export type AnswerType = 'price' | 'founding' | 'headcount' | 'statistics' | 'clients' | 'hours';

const ANSWER_TYPE_RULES: { type: AnswerType; question: RegExp; evidence: RegExp }[] = [
  {
    type: 'price',
    question: /\b(price[sd]?|pricing|fees?|how much|quotes?|rate card|biaya|harga|tarif|ongkos)\b|\b(what|how)\b[^.?!]{0,25}\bcosts?\b|\bcosts? (of|for) (your|the|inpartner|a|an)\b/i,
    evidence: /\b(rp|idr|usd|sgd|eur)\.?\s?\d|[$€£]\s?\d|\bpricing\b|\bfees?\b/i,
  },
  {
    type: 'founding',
    question: /\b(founded|founders?|founding|since when|history of|didirikan|pendiri|berdiri sejak|kapan berdiri)\b|\bwhen (was|were|did)\b[^.?!]{0,40}\b(start|found|establish|begin|created)/i,
    // "We support founders" is not a founding statement: require a year or "founded by".
    evidence: /\b(founded|established|since)\b[^.\n]{0,40}\b(19|20)\d{2}\b|\b(19|20)\d{2}\b[^.\n]{0,40}\b(founded|established)\b|\b(founded|established) by\b/i,
  },
  {
    type: 'headcount',
    question: /\bhow many\s+(employees|staff|people|consultants|advisors|team members|offices)\b|\b(number of|jumlah)\s+(employees|staff|karyawan|pegawai|kantor)\b|\bteam size\b/i,
    evidence: /\b\d[\d,.]*\+?\s+(employees|staff|people|consultants|advisors|professionals|offices|karyawan)\b/i,
  },
  {
    type: 'statistics',
    question: /\b(success rate|track record|win rate|percentage|statistics?|persentase|tingkat keberhasilan)\b|\b(how many|number of|jumlah)\s+(clients|customers|deals|projects|companies|transactions|klien|proyek)\b/i,
    evidence: /\d\s?%|\b\d[\d,.]*\+?\s+(clients|customers|deals|projects|companies|transactions)\b/i,
  },
  {
    type: 'clients',
    question: /\b(who are|list|name)\b[^.?!]{0,30}\b(clients|customers)\b|\b(clients?|customers?)\s+(list|names?|portfolio)\b|\bcase stud(y|ies)\b|\btestimonials?\b|\bportfolio\b|\bstudi kasus\b|\btestimoni\b|\b(siapa( saja)?|daftar|nama)\s+klien\b/i,
    evidence: /\b(case stud(y|ies)|testimonials?|portfolio|our clients include|clients include|trusted by)\b/i,
  },
  {
    type: 'hours',
    question: /\b(office|opening|business|working)\s+hours\b|\bjam\s+(kerja|operasional|buka|kantor)\b/i,
    evidence: /\b\d{1,2}[:.]\d{2}\b|\b\d{1,2}\s?(am|pm)\b|\b(monday|senin)\b/i,
  },
];

/** Returns the first answer type the question asks for that the evidence cannot supply, or null. */
export function missingAnswerType(question: string, evidenceText: string): AnswerType | null {
  for (const rule of ANSWER_TYPE_RULES) {
    if (rule.question.test(question) && !rule.evidence.test(evidenceText)) return rule.type;
  }
  return null;
}

const URL_RE = /\bhttps?:\/\/[^\s<>"']+|\bwww\.[^\s<>"']+/gi;
const EMAIL_RE = /\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b/gi;
const PHONE_RE = /\+?\d[\d\s().-]{6,}\d/g;
const NUMBER_RE = /\d+(?:[.,]\d+)*/g;

const digitsOnly = (s: string) => s.replace(/\D/g, '');

/**
 * Lists concrete claims in an answer that the evidence does not contain:
 * URLs, e-mail addresses, phone numbers and any other number. Returns the
 * claim kinds only (never the text) so callers can log them safely.
 */
export function unsupportedClaims(answer: string, evidenceText: string): string[] {
  const problems: string[] = [];
  const evidenceLower = evidenceText.toLowerCase();
  const evidenceDigits = digitsOnly(evidenceText);
  const evidenceNumbers = new Set(evidenceText.match(NUMBER_RE) ?? []);

  // The model is told not to output links; any URL it writes is unsupported unless quoted from a source.
  if ((answer.match(URL_RE) ?? []).some((u) => !evidenceLower.includes(u.toLowerCase().replace(/[.,;:!?)]+$/, '')))) {
    problems.push('url');
  }
  let rest = answer.replace(URL_RE, ' ');

  if ((rest.match(EMAIL_RE) ?? []).some((e) => !evidenceLower.includes(e.toLowerCase()))) problems.push('email');
  rest = rest.replace(EMAIL_RE, ' ');

  for (const phone of rest.match(PHONE_RE) ?? []) {
    const digits = digitsOnly(phone);
    // Only long digit runs are phone-like; shorter ones are checked as numbers below.
    if (digits.length >= 8) {
      if (!evidenceDigits.includes(digits)) problems.push('phone');
      rest = rest.replace(phone, ' ');
    }
  }

  // Line-leading list markers ("1. ", "2) ") and source references ("S1") are formatting, not claims.
  rest = rest.replace(/(^|\n)\s*\d{1,2}[.)]\s/g, '$1').replace(/\bS\d{1,2}\b/g, ' ');
  if ((rest.match(NUMBER_RE) ?? []).some((n) => !evidenceNumbers.has(n))) problems.push('number');

  return [...new Set(problems)];
}
