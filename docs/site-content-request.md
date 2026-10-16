# Permintaan konten situs untuk Pemilik Situs Inpartner

**Tujuan.** Chatbot hanya boleh menjawab dari teks yang benar-benar tayang di inpartnerglobal.com, karena setiap jawaban menautkan halaman sumbernya. Versi 2.0 dari "Company Profile" berisi fakta yang belum ada di situs, jadi fakta itu dikeluarkan dari chatbot pada 2026-10-16 (hasil cek: hanya 0–8% teksnya ditemukan di halaman yang dikutip, dan `/faq/` memberi 404). Dokumen ini daftar apa yang perlu ditayangkan agar fakta tersebut bisa dipakai chatbot lagi.

**Yang diminta dari Anda:** (1) setujui atau koreksi teks di bagian B, (2) jawab 4 pertanyaan di bagian A, (3) tayangkan teks yang disetujui, lalu beri tahu tim teknis. Tim teknis akan membangun ulang basis pengetahuan dan memverifikasinya terhadap situs.

Status "Sekarang" di bawah dicek terhadap situs pada 2026-10-16.

## A. Keputusan yang dibutuhkan

| # | Pertanyaan | Kenapa penting | Sekarang di situs |
|---|---|---|---|
| A1 | **Nomor telepon resmi yang mana?** | Chatbot tidak memberi nomor sampai ini jelas. | Halaman Contact menampilkan tiga nomor: **+62 896 2831 0192**, **(406) 555-0120** (tampak seperti placeholder bawaan template) dan **+62 859-3454-8202** (di bagian "Information"). Company Profile menyebut +62 859 3454 8202. |
| A2 | **Apakah angka "90+ proyek, 70+ klien, 10+ klien terkait asing" boleh dipublikasikan?** | Angka ini hanya ada di Company Profile. Bila tayang, chatbot boleh menyebutnya dengan tautan sumber. | Belum ada di situs. |
| A3 | **Pengalaman: "sejak 2019" atau "20+ tahun"?** | Teks Company Profile bertentangan: sejarah menyebut usaha berawal 2009 dan menjadi konsultan manajemen pada 2019, tetapi keunggulan menyebut "20+ tahun pengalaman strategic advisory". Chatbot tidak boleh mengarang jembatan di antaranya. | Belum ada. |
| A4 | **Apakah domain email `inpartner.id` (corporatesecretary@inpartner.id) memang resmi?** Dan apakah URL Contact boleh diganti ke `https://inpartnerglobal.com/contact/`? | Dipakai sebagai kontak dan tautan "hubungi kami". | Email sudah tampil di situs; URL Contact di plugin masih homepage. |

## B. Teks yang perlu tayang (usulan, silakan edit)

Teks Inggris dipakai karena situs berbahasa Inggris. Cukup tayang dalam satu bahasa, chatbot menerjemahkan jawabannya.

### B1. Halaman About (`/about-us/`): tambah dua bagian

Sekarang: tidak ada nama legal, tahapan konsultasi, maupun angka kinerja.

> **Who we are.** INPARTNER (Inpartner Consulting) is the brand of **PT Inpartner Optima Integra**, a Business & Management Consulting firm that helps businesses navigate markets, identify opportunities, build strategic partnerships, and expand across markets and industries.
>
> **Our 5-stage approach**
> 1. **Understand.** Understand client objectives, challenges, and requirements.
> 2. **Analyze.** Assess market, industry, financial, and regulatory context.
> 3. **Identify.** Identify opportunities, partners, investors, and solutions.
> 4. **Connect.** Facilitate introductions, negotiations, and strategic partnerships.
> 5. **Execute & Grow.** Support implementation, market entry, and business expansion.
>
> This structured framework ensures tailored advisory from problem diagnosis to measurable outcomes.

### B2. Sejarah dan nilai perusahaan (di About atau Home)

Hanya bila A3 sudah dijawab. Teks Company Profile:

> **History.** The business originated in 2009 with an initial focus on market access, financing, technology, productivity and capacity building for MSMEs in East Java. In 2019 it transformed into business and management consulting services for middle-sized and large corporations.
>
> **Values.** Integrity (building trust through transparency) · Connectivity (bridging businesses, markets and opportunities) · Client-Centric (delivering solutions that create value) · Global-Local (combining global perspective with local insight) · Sustainable Impact (creating long-term growth and value).

Jangan tayangkan baris "20+ years of strategic advisory experience" sebelum A3 jelas.

### B3. Track record (di About, bagian baru)

Hanya bila A2 disetujui:

> **Track record (company-stated, 2026 company profile).** 90+ advisory projects · 70+ corporate, private and state-owned (BUMN) clients · 10+ multinational and cross-border clients.

### B4. Halaman FAQ baru: `/faq/` (atau ubah URL di Company Profile)

Halaman ini sekarang 404, padahal pernah dikutip chatbot. Isi yang cocok untuk **pengunjung** (bukan catatan internal):

> **What is INPARTNER?** An independent Business & Management Consulting firm. We are not a licensed securities broker, bank, or tax accounting firm.
>
> **Do you give investment advice, cryptocurrency or stock recommendations?** No. Content on macroeconomics, stock markets, cryptocurrency, commodities, monetary or fiscal policy is editorial and research content, not a client service or an investment recommendation. INPARTNER does not provide cryptocurrency trading or retail financial investment advisory.
>
> **Is Executive Search / Head Hunting a service?** Yes, as a client-facing advisory service to source leadership for client organizations. INPARTNER does not use this site for its own job vacancies or CVs.
>
> **Can you share fees, client contracts or deal figures?** Specific commercial terms, non-public client contracts and proprietary transaction figures are confidential.
>
> **How do I ask for a proposal or a feasibility assessment?** Contact us at corporatesecretary@inpartner.id or [nomor telepon sesuai A1].
>
> (Tambahkan angka track record dari B3 bila disetujui.)

### B5. Halaman Contact (`/contact/`): rapikan

- Hapus nomor placeholder **(406) 555-0120** dan teks template "Ready to take control of your financial future?".
- Sisakan **satu** nomor telepon yang dipilih di A1, konsisten di blok kontak dan di bagian "Information" (footer).
- Alamat sudah ada dan konsisten: Pakuwon Tower 10th Floor Unit J, Jl. Raya Casablanca Kav. 88, Jakarta Selatan. Nama legal PT Inpartner Optima Integra sebaiknya tampil jelas di halaman ini.

### B6. Halaman Cross-Border M&A (`/cross-border-ma-advisory/`)

Halaman ini ada di menu tetapi teksnya tidak cukup untuk dijawab chatbot ("No content is added yet" di bagian Insight, tanpa uraian layanan). Isi uraian layanan joint venture, technology transfer, dan knowledge transfer (versi Company Profile ada, bisa dipakai), atau sembunyikan dari menu.

## C. Jangan ditayangkan apa adanya

Bagian Company Profile berikut adalah catatan internal tim, bukan teks untuk pengunjung. Tetap disimpan di sisi chatbot (aturan di backend), tidak perlu ada di situs:

- "Target Audience & Recommended Actions" (siapa target pesan pemasaran).
- "Client Need to Service Routing" (tabel pengarahan kebutuhan ke layanan). Bila ingin dipakai, ubah menjadi FAQ "Layanan mana yang cocok untuk saya?".
- "AI Knowledge Base Governance & Chatbot Boundaries" (aturan internal chatbot). Versi ramah pengunjungnya ada di B4.

## D. Setelah tayang: langkah tim teknis

1. `cd backend && npm run kb:verify-sources` terhadap URL yang sudah tayang.
2. Tambahkan halaman baru (mis. `/faq/`) ke `backend/data/sources.json`, lalu `npm run kb:build` dan `npm run kb:validate`.
3. Hapus aturan pengecualian telepon di `sources.json` hanya bila A1 sudah dijawab. Jalankan `npm test`, `npm run eval:retrieval -- --min-stopped=1` dan eval held-out.
4. Di WordPress: Knowledge Base → sinkronkan halaman yang diperbarui, uji beberapa pertanyaan di staging.

Pertanyaan yang akan bisa dijawab chatbot setelah ini (dan sekarang dijawab dengan "belum ada informasi"): nama legal perusahaan, tahapan konsultasi, jumlah proyek dan klien, tahun berdiri atau sejarah, batasan layanan (bukan broker/bank/konsultan pajak), dan cara meminta proposal.
