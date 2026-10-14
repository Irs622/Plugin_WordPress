# PRODUCT REQUIREMENTS DOCUMENT (PRD)
## Inpartner AI Business Consultation Assistant

**Versi:** 1.2 Final  
**Status:** Ready for Prototyping  
**Target Website:** https://inpartnerglobal.com  
**Platform:** WordPress Plugin  
**Nama Plugin:** WP Knowledge Chat  
**Target Pengguna:** Pengunjung website dan calon klien Inpartner  
**Model Implementasi:** WordPress Plugin + Backend AI  
**Prioritas:** MVP dengan biaya operasional rendah

---

## 1. Executive Summary

Inpartner AI Business Consultation Assistant adalah chatbot berbasis AI yang terintegrasi dengan website Inpartner untuk membantu pengunjung memahami layanan perusahaan, memperoleh jawaban atas pertanyaan umum, menemukan halaman yang relevan, dan menghubungi tim Inpartner.

Chatbot menggunakan konten publik yang telah disetujui sebagai sumber pengetahuan. Sistem harus memberikan jawaban yang relevan, menyertakan tautan sumber, dan mengakui keterbatasan ketika informasi yang diperlukan tidak tersedia.

Produk dikembangkan sebagai plugin WordPress bernama WP Knowledge Chat. Plugin menangani widget, pengaturan admin, dan integrasi website, sedangkan backend terpisah menangani pemrosesan pertanyaan, retrieval informasi, keamanan API, dan integrasi model AI.

Fokus tahap pertama adalah membangun prototipe yang dapat diuji sebelum peluncuran produksi.

## 2. Problem Statement

Pengunjung website perusahaan konsultasi berpotensi mengalami kesulitan dalam menemukan informasi layanan, memahami kategori layanan, dan menentukan langkah berikutnya untuk menghubungi perusahaan.

Chatbot diharapkan membantu mengurangi hambatan tersebut melalui antarmuka percakapan yang sederhana dan berbasis sumber resmi.

Hipotesis produk yang akan diuji adalah bahwa chatbot berbasis knowledge base dapat meningkatkan kemudahan menemukan informasi dan membantu pengunjung menemukan jalur konsultasi yang sesuai.

## 3. Product Goals

1. Menyediakan akses cepat ke informasi layanan Inpartner.
2. Menjawab pertanyaan berdasarkan konten resmi website.
3. Membantu pengunjung menemukan halaman layanan yang relevan.
4. Mengarahkan pengunjung ke kanal kontak resmi.
5. Menjaga kualitas jawaban, keamanan, dan biaya operasional.
6. Menyediakan fondasi yang dapat dikembangkan setelah MVP tervalidasi.

### Non-Goals

MVP tidak mencakup konsultasi bisnis profesional secara penuh, rekomendasi investasi personal, eksekusi transaksi, integrasi CRM, integrasi WhatsApp, fine-tuning model, atau layanan SaaS multi-tenant.

## 4. Target Users

### Persona A: Business Decision Maker

Pimpinan atau pemilik perusahaan yang ingin memahami layanan konsultasi untuk kebutuhan bisnisnya.

### Persona B: Business Development Professional

Profesional yang mencari informasi terkait pengembangan bisnis, peluang pasar, atau layanan advisory.

### Persona C: Prospective Client

Pengunjung yang ingin mengetahui layanan Inpartner dan mencari cara untuk memulai komunikasi dengan tim perusahaan.

Persona tersebut merupakan asumsi awal yang perlu divalidasi dengan tim Inpartner dan data pengguna aktual.

## 5. Scope dan Prioritas MVP

### P0: Wajib

- Floating chat widget.
- Initial greeting.
- Input pesan dan tampilan percakapan.
- Suggested questions.
- Jawaban berdasarkan knowledge base.
- Tautan sumber jawaban.
- Fallback ketika informasi tidak tersedia.
- Loading dan error state.
- Tombol menuju halaman kontak.
- Pengaturan dasar di dashboard WordPress.
- Validasi input, keamanan output, dan rate limiting.

### P1: Pengembangan lanjutan

- Sinkronisasi konten otomatis.
- Pengaturan sumber knowledge base dari dashboard.
- Logging dan analitik penggunaan.
- Formulir lead capture opsional.
- Rekomendasi kategori layanan yang lebih terstruktur.

### Out of Scope

- Voice assistant.
- CRM automation.
- Integrasi WhatsApp.
- Dashboard analitik kompleks.
- Multi-tenant SaaS.
- Agent yang menjalankan tindakan eksternal.
- Rekomendasi investasi personal.

## 6. User Journey

### 6.1 FAQ Journey

1. Pengunjung membuka website.
2. Pengunjung membuka widget chatbot.
3. Chatbot menampilkan pesan pembuka dan suggested questions.
4. Pengunjung memilih pertanyaan atau memasukkan pesan.
5. Backend mencari sumber yang relevan.
6. Model AI menyusun jawaban berdasarkan konteks.
7. Chatbot menampilkan jawaban dan tautan sumber.
8. Pengunjung dapat membuka halaman sumber atau bertanya kembali.

### 6.2 Service Discovery Journey

1. Pengunjung memilih opsi mencari layanan yang sesuai.
2. Chatbot meminta penjelasan singkat mengenai kebutuhan bisnis.
3. Sistem mencari informasi layanan yang relevan.
4. Chatbot menjelaskan kategori layanan dan alasan relevansinya.
5. Pengunjung dapat membuka halaman layanan atau menuju kanal kontak resmi.

### 6.3 Fallback Journey

1. Pengunjung menanyakan informasi yang tidak tersedia.
2. Sistem tidak menemukan sumber yang cukup.
3. Chatbot mengakui keterbatasan informasi.
4. Chatbot menawarkan tautan kontak resmi.

## 7. Functional Requirements

| ID | Requirement | Prioritas |
|---|---|---|
| FR-01 | Widget dapat dibuka dan ditutup | P0 |
| FR-02 | Pengunjung dapat mengirim pesan | P0 |
| FR-03 | Backend memproses pertanyaan dan mencari konteks relevan | P0 |
| FR-04 | Jawaban didasarkan pada sumber yang ditemukan | P0 |
| FR-05 | Jawaban menyertakan URL sumber yang valid | P0 |
| FR-06 | Sistem menyediakan fallback untuk informasi yang tidak tersedia | P0 |
| FR-07 | Widget menyediakan suggested questions | P0 |
| FR-08 | Widget menampilkan loading dan error state | P0 |
| FR-09 | Widget responsif untuk desktop dan mobile | P0 |
| FR-10 | Widget menyediakan tautan kontak | P0 |
| FR-11 | Admin dapat mengubah konfigurasi dasar | P0 |
| FR-12 | Sistem membatasi penggunaan endpoint chat | P0 |
| FR-13 | Admin dapat mengelola sumber konten | P1 |
| FR-14 | Sistem menyediakan sinkronisasi knowledge base | P1 |
| FR-15 | Sistem mencatat metrik dan error minimal | P1 |
| FR-16 | Pengunjung dapat mengirim lead secara sukarela | P1 |

## 8. Admin Settings Specification

Menu admin:

**WordPress Dashboard → WP Knowledge Chat → Settings**

Pengaturan MVP terdiri atas empat field utama.

| Field | Key | Type | Default |
|---|---|---|---|
| Enable Chatbot | `enabled` | Boolean | `true` |
| Greeting Message | `greeting_message` | String | `Hello! How can I help you explore Inpartner's services?` |
| Default Language | `language` | Enum | `en` |
| Contact URL | `contact_url` | URL | `https://inpartnerglobal.com` |

### 8.1 Enable Chatbot

Mengaktifkan atau menonaktifkan widget pada halaman yang telah dikonfigurasi.

Validasi: hanya menerima boolean. Jika nonaktif, widget tidak dimuat atau ditampilkan.

### 8.2 Greeting Message

Menentukan pesan pembuka ketika pengunjung membuka chatbot.

Validasi: wajib diisi, maksimal 500 karakter, dan ditampilkan dengan escaping yang tepat.

### 8.3 Default Language

Menentukan bahasa default antarmuka dan instruksi bahasa respons.

Nilai yang diizinkan: `en` dan `id`.

### 8.4 Contact URL

Menentukan tautan untuk menghubungi tim Inpartner.

Validasi: URL absolut menggunakan HTTPS, host valid, tanpa kredensial URL, dan sesuai daftar domain yang disetujui.

URL awal adalah homepage Inpartner. Sebelum produksi, verifikasi apakah halaman kontak khusus tersedia dan gunakan URL tersebut jika sesuai.

### 8.5 Penyimpanan Konfigurasi

Simpan konfigurasi menggunakan WordPress Options API dengan nama option:

`wp_knowledge_chat_settings`

Struktur konfigurasi:

```json
{
  "enabled": true,
  "greeting_message": "Hello! How can I help you explore Inpartner's services?",
  "language": "en",
  "contact_url": "https://inpartnerglobal.com"
}
```

Gunakan Settings API untuk registrasi dan penyimpanan pengaturan. Semua nilai harus divalidasi di server. Field yang tidak tersedia pada konfigurasi lama harus menggunakan default yang sesuai.

API key tidak disimpan pada konfigurasi umum yang dapat ditampilkan ke frontend. Secret AI dikelola di backend melalui environment variable atau mekanisme secret server yang sesuai.

## 9. UI/UX Requirements

### 9.1 Widget Layout

Widget terdiri atas:

- Floating button di pojok kanan bawah.
- Header dengan identitas AI assistant.
- Area percakapan.
- Suggested questions.
- Input pesan.
- Tombol kirim.
- Loading indicator.
- Error dan fallback state.
- Tautan sumber jawaban.

### 9.2 Visual Design

Desain mengikuti identitas visual resmi website Inpartner, termasuk warna, tipografi, dan gaya komponen yang relevan.

Widget tidak boleh menghalangi navigasi utama atau konten penting. Pada perangkat mobile, panel chat harus menyesuaikan ukuran layar.

### 9.3 Accessibility

- Navigasi keyboard.
- Fokus kontrol yang terlihat.
- Label aksesibel.
- Pengumuman status dan pesan baru bagi screen reader.
- Kontras teks yang memadai.
- Tidak menggunakan warna sebagai satu-satunya indikator status.

## 10. Technical Architecture

### 10.1 WordPress Plugin

Tanggung jawab:

- Memuat widget JavaScript dan CSS.
- Menyediakan halaman pengaturan admin.
- Menghubungkan widget dengan backend.
- Mengatur halaman tempat chatbot ditampilkan.
- Mengelola konfigurasi dasar.
- Mengelola sumber konten jika fitur tersebut telah tersedia.

### 10.2 Backend AI

Tanggung jawab:

- Memvalidasi request.
- Menerapkan rate limiting.
- Mencari konteks yang relevan.
- Mengelola prompt dan konteks.
- Memanggil model AI.
- Memeriksa struktur respons dan sumber.
- Mengelola timeout, error, dan logging.

### 10.3 Knowledge Base

Sumber awal yang dapat dipertimbangkan:

- Homepage.
- Halaman layanan.
- About Us.
- Halaman kontak.
- FAQ.
- Artikel publik yang telah disetujui.

Semua sumber harus diverifikasi terhadap konten aktual website. Setiap dokumen yang diindeks menyimpan ID, judul, URL, isi teks, status publikasi, kategori, dan waktu pembaruan.

Konten privat atau belum dipublikasikan tidak boleh diindeks secara default.

### 10.4 Proposed Technology Stack

| Komponen | Teknologi |
|---|---|
| CMS | WordPress |
| Tema | Hello Elementor |
| Plugin | PHP |
| Widget | Vanilla JavaScript dan CSS |
| Backend | Node.js + TypeScript |
| Model AI | Gemini API |
| Retrieval awal | Pencarian teks |
| Retrieval lanjutan | Embedding dan vector database jika diperlukan |
| Deployment | WordPress hosting dan backend eksternal |

Versi WordPress 7.1.3 dan PHP 8.5.11 sesuai laporan lingkungan menjadi acuan awal. Kompatibilitas aktual harus diverifikasi di staging.

### 10.5 API Contract

Endpoint minimum:

- `POST /api/chat`: memproses pertanyaan.
- `POST /api/index`: memperbarui indeks konten, dilindungi autentikasi server-ke-server.
- `GET /api/health`: memeriksa status layanan.

Contoh respons:

```json
{
  "answer": "Please explore our official service page for further information.",
  "sources": [
    {
      "title": "Service Information",
      "url": "https://inpartnerglobal.com"
    }
  ],
  "grounded": true
}
```

Field `grounded` bukan jaminan kebenaran. Backend harus memastikan sumber yang ditampilkan benar-benar mendukung jawaban.

## 11. Security Requirements

- API key tidak boleh dikirim ke browser.
- Input divalidasi berdasarkan panjang, format, dan batas penggunaan.
- Output dirender secara aman.
- Endpoint chat memiliki rate limiting.
- Endpoint administrasi dan indexing memerlukan otorisasi.
- WordPress admin menggunakan capability yang sesuai, nonce, sanitasi, dan escaping.
- Konten website diperlakukan sebagai data tidak tepercaya.
- Model tidak boleh mengungkap secret atau instruksi internal.
- Logging tidak menyimpan secret atau data pribadi yang tidak diperlukan.
- Lead capture harus memiliki dasar pemrosesan dan persetujuan yang sesuai.
- Error internal tidak boleh menampilkan stack trace kepada pengunjung.

## 12. Non-Functional Requirements

### Performance

Target awal: p95 waktu respons maksimal 8 detik untuk pertanyaan sederhana dalam lingkungan pengujian yang ditentukan.

Widget tidak boleh menghambat pemuatan halaman utama. Target waktu respons dievaluasi terpisah dari waktu pemuatan website.

### Reliability

- Timeout ditangani dengan pesan yang jelas.
- Retry dibatasi.
- Kegagalan provider tidak merusak halaman website.
- Fallback tersedia ketika API tidak dapat digunakan.

### Privacy

- Pengumpulan data pengguna diminimalkan.
- Lead capture bersifat opsional.
- Kebijakan retensi dan penghapusan data ditetapkan sebelum produksi.
- Percakapan tidak digunakan untuk pelatihan model tanpa dasar dan persetujuan yang sesuai.

## 13. Success Metrics

Target awal untuk evaluasi MVP:

| Metric | Target |
|---|---|
| Grounded answer accuracy | Minimal 90% pada dataset uji terkurasi |
| Valid source URLs | 100% pada jawaban yang menyertakan sumber |
| Unsupported factual claims | Tidak ada pada dataset uji yang disetujui |
| Successful chat requests | Minimal 95% saat provider tersedia |
| Task completion | Diukur melalui pengujian pengguna |
| Cost per conversation | Diukur selama pilot |

Dataset uji harus mencakup pertanyaan FAQ, parafrasa, pertanyaan ambigu, informasi yang tidak tersedia, dan prompt injection.

## 14. Acceptance Criteria

Prototipe dapat diterima apabila:

1. Plugin dapat diaktifkan dan dinonaktifkan.
2. Widget berjalan pada desktop dan mobile.
3. Pengguna dapat mengirim pesan dan menerima respons.
4. Jawaban berbasis sumber yang telah disetujui.
5. URL sumber yang ditampilkan valid dan relevan.
6. Pertanyaan tanpa sumber yang memadai menghasilkan fallback.
7. API key tidak terekspos pada frontend.
8. Endpoint chat memiliki validasi dan rate limiting.
9. Endpoint internal dilindungi autentikasi.
10. Widget menangani timeout dan error.
11. Pengaturan admin memiliki validasi server-side.
12. Pengguna tanpa capability yang sesuai tidak dapat mengubah pengaturan.
13. Konten privat tidak masuk ke knowledge base.
14. Pengujian keamanan dan aksesibilitas dasar telah dilakukan.
15. Plugin tidak menyebabkan kerusakan pada website selama pengujian staging.

## 15. Implementation Roadmap

### Phase 1: Discovery

- Audit konten website.
- Verifikasi layanan dan halaman sumber.
- Menentukan konten yang boleh digunakan.
- Menyusun dataset pertanyaan awal.

### Phase 2: UI Prototype

- Membuat widget.
- Mengimplementasikan greeting dan suggested questions.
- Membuat tampilan pesan, sumber, loading, dan error.
- Menguji desktop, mobile, dan aksesibilitas dasar.

### Phase 3: Functional MVP

- Mengembangkan plugin WordPress.
- Membuat backend AI.
- Mengintegrasikan model AI.
- Mengimplementasikan retrieval dan sumber jawaban.
- Menambahkan validasi, rate limiting, dan logging.

### Phase 4: Testing

- Evaluasi kualitas jawaban.
- Pengujian prompt injection dan XSS.
- Pengujian timeout dan kegagalan API.
- Pengujian performa dan biaya.
- Pengujian kompatibilitas di staging.

### Phase 5: Controlled Pilot

- Mengaktifkan chatbot pada halaman terpilih.
- Memantau pertanyaan yang tidak terjawab.
- Mengevaluasi kualitas, penggunaan, dan biaya.
- Memperbaiki knowledge base sebelum peluncuran yang lebih luas.

## 16. Risks and Mitigation

| Risiko | Mitigasi |
|---|---|
| Halusinasi AI | Retrieval, sumber, fallback, dan evaluasi |
| Informasi layanan tidak akurat | Validasi oleh tim Inpartner |
| Biaya API meningkat | Batas token, rate limit, dan monitoring |
| API key bocor | Secret disimpan di backend |
| Endpoint disalahgunakan | Validasi dan pembatasan request |
| Konten tidak sinkron | Sinkronisasi dan invalidasi indeks |
| Kebocoran data | Minimasi data, kontrol akses, dan retensi |
| Chatbot dianggap sebagai konsultan manusia | Identitas AI dan batasan yang jelas |
| Konflik dengan plugin atau tema | Pengujian kompatibilitas di staging |

## 17. Open Questions

Sebelum produksi, tim perlu mengonfirmasi:

1. Bahasa chatbot: Inggris saja atau Inggris dan Indonesia.
2. Halaman website prioritas untuk penempatan widget.
3. Daftar layanan resmi yang boleh direkomendasikan.
4. URL kontak resmi untuk eskalasi.
5. Batas biaya API bulanan.
6. Kebijakan penyimpanan percakapan dan data lead.
7. Penanggung jawab validasi knowledge base.

## 18. Final Product Direction

MVP berfokus pada tiga kemampuan:

**Answer:** memberikan jawaban berbasis sumber resmi.

**Guide:** membantu pengunjung menemukan informasi dan layanan yang relevan.

**Connect:** mengarahkan pengunjung ke tim Inpartner ketika memerlukan bantuan lebih lanjut.

Prioritas pengembangan adalah akurasi, keamanan, kemudahan penggunaan, dan pengendalian biaya. Fitur lanjutan hanya ditambahkan setelah MVP menunjukkan kualitas yang memadai melalui pengujian.