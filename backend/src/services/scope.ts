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
 * Patterns are written per category (English, Indonesian and Korean). Review
 * them when the knowledge base gains content in one of these areas. Korean
 * patterns use no \b: JavaScript word boundaries only see ASCII letters.
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
      /\bkamu sekarang\b|\bberperan(lah)? sebagai\b|\bberpura-pura(lah)?\b/i,
      /\bulang(i)?\b[^.?!]{0,30}\b(kata|kalimat|semua|teks)\b[^.?!]{0,20}\b(di atas|sebelumnya)\b/i,
      // Korean is verb-final: "이전 지시를 무시하고" puts the object first.
      /(지시|지침|규칙|명령|프롬프트)[^.?!]{0,30}(무시|잊어|잊고|어기|따르지)/,
      /(무시|잊어)[^.?!]{0,30}(지시|지침|규칙|명령|프롬프트)/,
      /(시스템|숨겨진|내부|초기)\s?(프롬프트|지시|지침|메시지|설정|규칙)/,
      /(api|API)\s?키|비밀\s?키|비밀번호|액세스\s?토큰/,
      /(너는|당신은)\s?이제|척\s?(해|하세요|해줘)|역할을\s?(해|하세요|해줘|맡아)|탈옥/,
      /(위|앞|이전)(의)?\s?(내용|문장|텍스트|글)[^.?!]{0,20}(반복|그대로)/,
      /(비공개|임시|초안)\s?(페이지|게시물|콘텐츠|문서)/,
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
      /주식|암호화폐|가상화폐|비트코인|코인\s?(추천|투자)/,
      /투자[^.?!]{0,15}(추천|해야|할까|좋을까|수익률)/,
      /(환율|주가|증시|코스피|루피아)[^.?!]{0,30}(오를|오르|내릴|떨어|전망|예측)/,
    ],
  },
  {
    kind: 'legal_tax_advice',
    patterns: [
      /\b(tax(es|ation)?|pajak|pph|ppn)\b/i,
      // Indirect taxes and duties: "VAT rate for imported goods" is a tax question without the word "tax".
      /\b(vat|gst|value[- ]added tax|customs dut(y|ies)|import dut(y|ies)|excise|bea (masuk|cukai)|cukai)\b/i,
      // Statutory capital requirements are regulatory facts the site does not publish.
      /\b(minimum|paid[- ]up)\s+(capital|investment)\b|\bcapital requirements?\b|\bmodal (minimum|minimal|disetor)\b/i,
      // Reviewing a visitor's own contract is legal advice; structuring agreements (a listed service) is not matched.
      /\blegal (risks?|review|opinion|issues?)\b|\b(review|check|vet)\b[^.?!]{0,30}\b(my|our)\b[^.?!]{0,20}\b(contracts?|agreements?)\b/i,
      /\brisiko hukum\b|\b(periksa|tinjau|cek)\b[^.?!]{0,30}\b(kontrak|perjanjian)\b/i,
      /\b(legal advice|lawyer|attorney|lawsuit|litigation|is it legal|legally)\b/i,
      /\b(register|incorporate|set up|form)\b[^.?!]{0,30}\b(a |an |my |our )?(company|pt|entity|legal entity|subsidiary)\b/i,
      /\bpt\s?pma\b|\bkbli\b|\boss[\s-]?rba\b/i,
      /\b(mendirikan|mendaftarkan)\b[^.?!]{0,20}\b(pt|perusahaan|badan usaha)\b/i,
      /세금|세무|법인세|부가세|부가가치세|소득세|관세/,
      /최소\s?자본금|자본금\s?(요건|기준)|법적\s?(리스크|위험|검토)|계약서[^.?!]{0,15}(검토|확인)/,
      /법률\s?(자문|상담|조언)|변호사|소송|합법(인가|적인가|일까)/,
      /(법인|회사|현지\s?법인)\s?(설립|등록)/,
    ],
  },
  {
    kind: 'medical',
    patterns: [/\b(medicine|medication|doctor|symptoms?|diagnos\w*|disease|illness|prescription|obat|dokter|gejala|penyakit)\b/i, /증상|질병|처방|병원|의약품/],
  },
  {
    kind: 'guarantee',
    patterns: [/\b(guarantee[sd]?|promise|assure)\b/i, /\b(jamin|menjamin|dijamin|garansi)\b/i, /보장|보증|장담/],
  },
  {
    kind: 'unrelated',
    patterns: [
      /\b(weather|cuaca|recipe|resep|joke|lelucon|poem|puisi|lyrics|horoscope|zodiac)\b/i,
      /\b(write|draft|compose|generate|create)\b[^.?!]{0,20}\b(poem|essay|story|code|script|business plan|cover letter|resume|cv|article)\b/i,
      /\b(buatkan|tuliskan|tulis)\b[^.?!]{0,20}\b(puisi|esai|cerita|kode|rencana bisnis|surat)\b/i,
      /\b(football|soccer|basketball|baseball|cricket|world cup|premier league|champions league|sepak ?bola|bulu ?tangkis)\b/i,
      /날씨|레시피|요리법|농담|운세|별자리|축구|야구|농구|월드컵/,
      /(시를|에세이|소설|이야기를|코드를|사업\s?계획서|자기소개서|이력서)[^.?!]{0,10}(써|작성)/,
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

export type AnswerType = 'price' | 'founding' | 'headcount' | 'statistics' | 'clients' | 'hours' | 'office_location';

const ANSWER_TYPE_RULES: { type: AnswerType; question: RegExp; evidence: RegExp }[] = [
  {
    type: 'price',
    // "pricing terms" / "pricing strategy" are negotiation topics (a listed service), not a request for Inpartner's prices.
    question: /\b(price[sd]?|pricing(?!\s+(terms?|strateg\w*|models?|negotiations?))|fees?|how much|quotes?|rate card|biaya|harga|tarif|ongkos)\b|\b(what|how)\b[^.?!]{0,25}\bcosts?\b|\bcosts? (of|for) (your|the|inpartner|a|an)\b|가격|비용|요금|수수료|얼마|견적/i,
    // A price needs an amount. The words "pricing"/"fees" alone are not one: KB v2.0 says
    // "Structure commercial agreements, pricing terms, ..." which opened the gate for every fee question.
    evidence: /\b(rp|idr|usd|sgd|eur|krw)\.?\s?\d|[$€£₩]\s?\d|\b(fees?|pricing|prices?|rates?|tarif|biaya|harga)\b[^.\n]{0,40}\d/i,
  },
  {
    type: 'founding',
    question: /\b(founded|founders?|founding|since when|history of|didirikan|pendiri|berdiri sejak|kapan berdiri)\b|\bwhen (was|were|did)\b[^.?!]{0,40}\b(start|found|establish|begin|created)|설립|창립|창업자|언제부터/i,
    // "We support founders" is not a founding statement: require a year or "founded by".
    evidence: /\b(founded|established|since)\b[^.\n]{0,40}\b(19|20)\d{2}\b|\b(19|20)\d{2}\b[^.\n]{0,40}\b(founded|established)\b|\b(founded|established) by\b/i,
  },
  {
    type: 'headcount',
    question: /\bhow many\s+(employees|staff|people|consultants|advisors|team members|offices)\b|\b(number of|jumlah)\s+(employees|staff|karyawan|pegawai|kantor)\b|\bteam size\b|직원\s?수|임직원\s?수|몇\s?명|팀\s?규모/i,
    evidence: /\b\d[\d,.]*\+?\s+(employees|staff|people|consultants|advisors|professionals|offices|karyawan)\b/i,
  },
  {
    type: 'statistics',
    question: /\b(success rate|track record|win rate|percentage|statistics?|persentase|tingkat keberhasilan)\b|\b(how many|number of|jumlah)\s+(clients|customers|deals|projects|companies|transactions|klien|proyek)\b|성공률|통계|(고객|프로젝트|거래|고객사)\s?(수|몇)/i,
    evidence: /\d\s?%|\b\d[\d,.]*\+?\s+(clients|customers|deals|projects|companies|transactions)\b/i,
  },
  {
    type: 'clients',
    question: /\b(who are|list|name)\b[^.?!]{0,30}\b(clients|customers)\b|\b(name|list)\b[^.?!]{0,30}\b(companies|firms|brands|businesses)\b[^.?!]{0,30}\b(helped|worked with|served|advised)\b|\b(clients?|customers?)\s+(list|names?|portfolio)\b|\bcase stud(y|ies)\b|\btestimonials?\b|\bportfolio\b|\bstudi kasus\b|\btestimoni\b|\b(siapa( saja)?|daftar|nama)\s+klien\b|고객\s?(목록|명단|리스트)|고객사\s?(이름|목록|명단)|(주요|대표)\s?고객|사례\s?연구|포트폴리오|후기/i,
    // Client names, not counts: "Project Portfolio: 90+ projects" and "Official Service Portfolio" (KB v2.0)
    // matched the former bare "portfolio" and let "Who are your clients?" through.
    evidence: /\b(case stud(y|ies)|testimonials?|our clients include|clients include|clients such as|trusted by)\b/i,
  },
  {
    type: 'hours',
    question: /\b(office|opening|business|working)\s+hours\b|\bjam\s+(kerja|operasional|buka|kantor)\b|(영업|근무|운영|업무)\s?시간/i,
    evidence: /\b\d{1,2}[:.]\d{2}\b|\b\d{1,2}\s?(am|pm)\b|\b(monday|senin)\b/i,
  },
];

const OFFICE_PLACE_RES: RegExp[] = [
  /\b(?:offices?|branch(?:es)?|representative office|presence)\s+(?:in|at)\s+((?:[\p{L}][\p{L}-]*)(?:\s+[\p{Lu}][\p{L}-]*)?)/iu,
  /\bkantor(?:\s+cabang)?\s+di\s+((?:[\p{L}][\p{L}-]*)(?:\s+[\p{Lu}][\p{L}-]*)?)/iu,
];
const NOT_A_PLACE = new Set(['the', 'your', 'our', 'a', 'an', 'any', 'which', 'what', 'other', 'another', 'many', 'multiple', 'several', 'other countries', 'mana', 'negara', 'luar']);
const OFFICE_EVIDENCE_LINE = /\b(office|kantor|address|alamat|headquarters?|tower|located)\b/i;

/**
 * "Do you have an office in London?" retrieves the contact page (it mentions
 * an office) although London appears nowhere: the place must be named on an
 * office/address line of the evidence. Latin-script place names only; Korean
 * place names are not mapped, so Korean questions skip this check.
 */
function missingOfficeLocation(question: string, evidenceText: string): boolean {
  for (const re of OFFICE_PLACE_RES) {
    const place = re.exec(question)?.[1]?.trim().toLowerCase();
    if (!place || NOT_A_PLACE.has(place) || NOT_A_PLACE.has(place.split(/\s+/)[0] ?? '')) continue;
    const lines = evidenceText.toLowerCase().split('\n');
    return !lines.some((line) => line.includes(place) && OFFICE_EVIDENCE_LINE.test(line));
  }
  return false;
}

/** Returns the first answer type the question asks for that the evidence cannot supply, or null. */
export function missingAnswerType(question: string, evidenceText: string): AnswerType | null {
  for (const rule of ANSWER_TYPE_RULES) {
    if (rule.question.test(question) && !rule.evidence.test(evidenceText)) return rule.type;
  }
  if (missingOfficeLocation(question, evidenceText)) return 'office_location';
  return null;
}

const URL_RE = /\bhttps?:\/\/[^\s<>"']+|\bwww\.[^\s<>"']+/gi;
const EMAIL_RE = /\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b/gi;
const PHONE_RE = /\+?\d[\d\s().-]{6,}\d/g;
const NUMBER_RE = /\d+(?:[.,]\d+)*/g;

const digitsOnly = (s: string) => s.replace(/\D/g, '');

/** Two or more consecutive capitalised Latin-script words, e.g. "John Smith", "PT Maju Jaya", "Acme Holdings". */
const NAME_RUN_RE = /(?<![\p{L}\d])[A-ZÀ-ÖØ-Þ][\p{Script=Latin}'’-]*(?:\s+(?:(?:&|of|and|de|van|bin|binti)\s+)?[A-ZÀ-ÖØ-Þ][\p{Script=Latin}'’-]*)+/gu;
const NAME_CONNECTORS = new Set(['&', 'of', 'and', 'de', 'van', 'bin', 'binti']);
/**
 * Capitalised words that are not names: sentence starters, pronouns and
 * common words in English and Indonesian, plus terms the assistant may use
 * without a source (its own name, languages, weekdays, months).
 */
const COMMON_CAPITALISED = new Set(
  (
    'a an the and or but if so yes no not of in on at to for from by with as is are was were be been this that these those it its ' +
    'we our us you your they their i my he she his her who what when where which why how there here also however certainly sure absolutely ' +
    'great thank thanks please hello hi welcome of course indeed additionally furthermore moreover overall currently generally in general ' +
    'for example such each every all some any many most more other another both either neither one two three first second finally next then ' +
    'ai assistant inpartner inpartner’s inpartner\'s english indonesian bahasa indonesia korean 한국어 ' +
    'monday tuesday wednesday thursday friday saturday sunday january february march april may june july august september october november december ' +
    'tentu ya tidak untuk dengan dan atau di ke dari pada yang ini itu kami kita anda mereka saya kamu silakan terima kasih selain selamat ' +
    'layanan tim apakah bagaimana apa siapa kapan mengapa dimana secara umum misalnya namun juga'
  ).split(/\s+/),
);

/**
 * Lists concrete claims in an answer that the evidence does not contain:
 * URLs, e-mail addresses, phone numbers, any other number, and capitalised
 * names (people, companies, places) absent from the evidence. Returns the
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

  // Names of people, companies or places that the evidence never mentions ("Our client PT Maju Jaya", "founded by
  // John Smith"). A heuristic: every word of a capitalised run must occur in the evidence or be a common word.
  const evidenceWords = new Set(evidenceLower.match(/[\p{L}'’-]+/gu) ?? []);
  for (const run of rest.match(NAME_RUN_RE) ?? []) {
    const words = run.split(/\s+/).filter((w) => !NAME_CONNECTORS.has(w.toLowerCase()));
    const unknown = words.filter((w) => {
      const lower = w.toLowerCase().replace(/['’]s$/, '');
      return !evidenceWords.has(lower) && !COMMON_CAPITALISED.has(lower);
    });
    if (unknown.length > 0) {
      problems.push('name');
      break;
    }
  }

  // Line-leading list markers ("1. ", "2) ") and source references ("S1") are formatting, not claims.
  rest = rest.replace(/(^|\n)\s*\d{1,2}[.)]\s/g, '$1').replace(/\bS\d{1,2}\b/g, ' ');
  if ((rest.match(NUMBER_RE) ?? []).some((n) => !evidenceNumbers.has(n))) problems.push('number');

  return [...new Set(problems)];
}
