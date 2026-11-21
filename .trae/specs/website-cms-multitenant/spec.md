# Website CMS Multi-Tenant LBA Cloud — Product Requirements Document

## Overview
- **Summary**: Sistem CMS frontpage publik yang dapat diedit dari panel Principal (untuk website LBA Cloud) dan panel Clinic (untuk website masing-masing mitra/klinik), dengan URL fallback `/klinik/{tenant-slug}`, dukungan domain kustom terverifikasi, registrasi & login pasien per klinik, serta form booking pengunjung yang dapat dilanjutkan ke portal pasien.
- **Purpose**: Memungkinkan setiap mitra/klinik memiliki website publik sendiri yang terisolasi (konten, media, dan riwayat revisi tersimpan di DB tenant masing-masing), dan pasien dapat mendaftar/login/booking dari website klinik, tanpa membuat aplikasi Laravel kedua atau sistem autentikasi baru.
- **Target Users**: Principal Super Admin (kelola website LBA Cloud + domain), Clinic Admin/Tenant Owner (kelola website klinik), pasien (daftar & login), staf/dokter (login dari website klinik), pengunjung (booking dari frontpage).

## Goals
- Website LBA Cloud (`/`) dapat diedit dari Principal tanpa mengubah source code; konten platform tersimpan di **central DB**.
- Setiap tenant memiliki frontpage sendiri di `/klinik/{slug}` dan domain kustom terverifikasi; konten, media, dan revisi website klinik tersimpan di **DB tenant masing-masing**.
- Clinic Admin/Tenant Owner dapat mengedit website kliniknya sendiri tanpa menyentuh website tenant lain.
- Pasien dapat mendaftar (dengan verifikasi email), login, dan masuk portal dari website klinik.
- Staf/dokter/admin dapat login dari website klinik menggunakan autentikasi existing.
- Pengunjung dapat mengirim booking dari frontpage klinik; setelah verifikasi email, permintaan yang sama dilanjutkan di portal tanpa input ulang.
- Seluruh tenant tetap terisolasi; domain tidak pernah menjadi otorisasi untuk data klinis.

## Non-Goals
- Tidak membuat aplikasi Laravel kedua atau database bersama untuk rekam medis.
- Tidak membuat sistem autentikasi baru yang bersaing dengan yang existing.
- Tidak memindahkan data klinis ke central DB.
- Tidak mengaktifkan cross-tenant patient record merge.
- Tidak membolehkan arbitrary HTML/PHP/JS di editor CMS.
- Tidak mengubah routing `/principal`, `/clinic`, `/patient`, `/login`, atau `/api/v1` yang sudah aktif.
- Tidak membuat salinan konten CMS klinik di central DB.

## Background & Context
- Repo `viacom-id/lba-cloud`, Laravel 13 + PHP 8.4 + Filament 5 + Livewire 4 + TailAdmin + Blade + Vite.
- Landing page existing di `routes/web.php:22` memakai `landing.blade.php` (konten hardcoded, tidak bisa diedit).
- Staff login unified di `/login` (Filament `StaffLogin`); patient login di `/patient/login` (Filament patient panel).
- `TenantDomain` + `TenantDomainResolver` sudah ada untuk resolusi host → tenant (central, verified-only, fail-closed), tapi belum di-wire ke routing publik.
- `PatientAccountLinker` ada untuk mengikat rekam medis existing ke akun user via undangan klinik (bukan self-registration).
- MFA TOTP sudah ada (`app_authentication_secret`).
- Permission `tenant.site.manage` dan `platform.site.manage` belum ada.
- `Tenant::slug` ada; `Brand ≠ Tenant` dipisah via `tenant_brands`.
- **Arsitektur DB tenant**: setiap tenant punya DB klinis terisolasi (`config/tenancy.php`, `TenantDatabaseManager`). Migrasi tenant berada di `database/migrations/tenant/` dan dijalankan via `lba:tenant:migrate:registered` untuk tenant existing atau `lba:clinic:setup` untuk tenant baru (termasuk mode manual cPanel).
- **Tidak ada email verification Laravel standard**: `MustVerifyEmail` tidak diimplementasikan; `email_verified_at` hanya di-set saat perubahan email login (`AccountEmailChanger`). Pasien tidak punya `email_verified_at`.
- **Booking module existing**: `Appointments` module (tenant DB) dengan `AppointmentScheduler` (create/hold/confirm), `BranchAvailability` (slot), `Appointment` status lifecycle (held→scheduled→confirmed→...). Patient portal booking di `app/Filament/Patient/Pages/Booking.php`.
- **Storage**: private disk (`lba`, `lba-reports`, `account-photos`) untuk file klinis; `public` disk untuk aset publik. Tidak ada path traversal; semua file klinis hanya bisa diakses via streaming endpoint terautentikasi.

## Functional Requirements

### CMS Platform (central DB)
- **FR-1**: Principal Super Admin dapat mengedit konten website LBA Cloud (`/`) melalui editor section-based di panel Principal (permission `platform.site.manage`), termasuk logo, favicon, nama website, warna, tipografi, hero, layanan, fitur, workflow, testimoni, FAQ, CTA, kontak, footer, SEO metadata, dan teks multibahasa (id/en/ms-MY).
- **FR-2**: Editor mendukung draft, preview, publish, riwayat revisi, restore revisi, dan audit siapa yang mengubah/mempublikasikan.
- **FR-3**: Jika belum ada konfigurasi CMS published, landing page existing (`landing.blade.php`) ditampilkan sebagai fallback.
- **FR-4**: Konten CMS platform tersimpan di central DB (tabel `site_pages`, `site_page_revisions`, `site_media` dengan `owner_type=platform`).

### CMS Klinik (tenant DB)
- **FR-5**: Setiap tenant memiliki frontpage sendiri di URL fallback `/klinik/{tenant-slug}`. Konten, media, revisi, dan pengaturan website klinik tersimpan di **DB tenant masing-masing** (tabel `site_pages`, `site_page_revisions`, `site_media` di `database/migrations/tenant/`), bukan di central DB.
- **FR-6**: Untuk request publik `/klinik/{slug}` atau domain kustom, aplikasi meresolve tenant aktif via slug/domain terverifikasi, menginisialisasi koneksi tenant DB (mekanisme existing `TenantDatabaseManager`), lalu membaca konten **published** dari DB tenant tersebut.
- **FR-7**: Jika DB tenant dapat diakses tetapi belum ada konten published, tampilkan **template bawaan klinik** (header, hero, tentang, layanan publik, lokasi cabang aktif, FAQ default, CTA daftar/login/booking, footer). Template hanya memakai data publik tenant (nama, brand, cabang aktif) — tidak ada data klinis.
- **FR-8**: Jika DB tenant gagal diakses, tampilkan pesan "layanan sementara tidak tersedia" **tanpa detail koneksi**. Koneksi dan cache tetap terisolasi per tenant.
- **FR-9**: Clinic Admin atau Tenant Owner dengan permission `tenant.site.manage` dapat mengedit website kliniknya sesuai konteks membership-nya. Dua klinik dengan Brand NuBiom yang sama punya konten, media, dan revisi masing-masing (tenant sebagai pemilik website).
- **FR-10**: Perubahan website klinik hanya berlaku untuk tenant aktif. Staf biasa, pasien, dan tenant lain tidak dapat mengubah website tenant tersebut.
- **FR-11**: Migrasi CMS tenant tersedia untuk tenant lama maupun baru, termasuk mode database manual cPanel (via `lba:tenant:migrate:registered` / `lba:clinic:setup`).

### Domain Kustom
- **FR-12**: Domain kustom terverifikasi (via `tenant_domains`) menyajikan frontpage tenant pada root `/`; host pusat tetap menyajikan website Principal.
- **FR-13**: **Hanya Principal Super Admin** yang boleh menambah, mengubah, memverifikasi, menetapkan domain utama, atau menghapus domain. Pembatasan diterapkan di server dan panel. Clinic Admin dan Tenant Owner hanya mengelola konten website.
- **FR-14**: Domain dan slug hanya menentukan konteks website. Login dan akses portal tetap memerlukan role serta membership yang valid.
- **FR-15**: Tolak domain tidak dikenal atau belum terverifikasi pada jalur website tenant, serta tenant nonaktif atau terhapus (fail-closed).
- **FR-16**: Periksa benturan rute dengan `/principal`, `/clinic`, `/patient`, `/login`, `/api/v1`, dan webhook. Jika alur login dari domain kustom diteruskan ke host utama, gunakan URL aplikasi tepercaya dan pertahankan konteks klinik secara tervalidasi.

### Registrasi & Login Pasien
- **FR-17**: Setiap frontpage klinik menyediakan tombol Daftar Pasien, Masuk Pasien, Masuk Tim Klinik, dan Booking. Semua memakai guard, panel, session, dan alur autentikasi existing.
- **FR-18**: Masuk Pasien memakai email + password. Verifikasi akun cukup melalui **email**. Nomor HP tetap opsional dan tidak dipakai untuk login atau verifikasi.
- **FR-19**: Untuk pendaftaran baru: buat `User` di central DB (role `PATIENT_CUSTOMER`), `TenantMembership` untuk tenant tujuan, dan satu `Patient` record minimal berstatus **self-registered/unverified** di DB tenant tersebut. Verifikasi email memakai mekanisme existing (`AccountEmailChanger` pola signed link) atau perluasan yang sama.
- **FR-20**: Tahan akses portal sampai email terverifikasi dan provisioning selesai. Verifikasi email hanya membuktikan kepemilikan email; status verifikasi identitas klinis mengikuti proses klinik.
- **FR-21**: Jika email sudah memiliki akun, arahkan ke login existing dengan respons yang **tidak mengungkap keberadaan akun**. Jangan buat `User` duplikat.
- **FR-22**: Jika pemilik akun sudah login tetapi belum punya membership di klinik tujuan, lanjutkan pendaftaran klinik melalui aksi eksplisit yang tervalidasi. Jangan mengubah role akun existing.
- **FR-23**: Jangan mencari atau mengikat rekam medis lama berdasarkan kecocokan nama, email, atau nomor HP. Linking record existing tetap melalui undangan klinik dan `PatientAccountLinker`.
- **FR-24**: Karena `User`/membership (central) dan `Patient` (tenant) berada di dua database, **jangan mengandalkan satu `DB::transaction` lintas koneksi**. Provisioning harus aman saat gagal sebagian, diulang, atau terjadi submit ganda (idempotent). Portal tidak boleh terbuka dalam kondisi data setengah jadi.
- **FR-25**: Admin, staf, dan dokter masuk lewat alur staf existing dengan cek role serta membership di klinik yang dipilih. MFA Principal tetap berjalan.

### Booking dari Frontpage & Portal
- **FR-26**: Pasien yang sudah login dan punya membership di klinik tujuan diarahkan ke alur booking existing di portal pasien, dengan konteks klinik yang benar.
- **FR-27**: Pengunjung yang belum login atau belum punya akun bisa membuka dan mengirim **form booking** dari frontpage klinik. Form menampilkan pilihan publik dari modul booking existing: layanan, lokasi/cabang, dan slot yang tersedia (via `BranchAvailability`).
- **FR-28**: Form booking publik meminta data kontak minimum: nama, email, serta persetujuan privasi. Tidak memerlukan data medis. Nomor HP opsional.
- **FR-29**: Permintaan booking disimpan untuk tenant yang benar dalam status **menunggu verifikasi email** (tabel `booking_requests` di tenant DB). Pesan menjelaskan bahwa pengajuan belum menjadi jadwal terkonfirmasi.
- **FR-30**: Setelah email terverifikasi, arahkan pengguna untuk menyelesaikan registrasi atau login yang sesuai, lalu lanjutkan permintaan booking yang **sama** di portal tanpa input ulang. Hanya pemilik permintaan di tenant yang benar yang dapat melanjutkan.
- **FR-31**: Saat konfirmasi booking di portal, gunakan aturan booking existing untuk masa berlaku hold (jika dipakai), validasi ulang slot sebelum konfirmasi (`BranchAvailability`), dan cegah booking ganda saat retry atau bentrok slot (`AppointmentScheduler`).

### Keamanan & Isolasi
- **FR-32**: Draft tidak muncul di halaman publik; hanya konten published yang terlihat. Preview draft hanya untuk editor berwenang.
- **FR-33**: Restore revisi bekerja; audit mencatat setiap publish/restore.
- **FR-34**: File media CMS per tenant dipisahkan dari file medis pasien. Akses publik terbatas pada media yang dipublikasikan; preview draft hanya untuk editor berwenang.
- **FR-35**: Rich text editor terbatas dan disanitasi; tidak ada arbitrary PHP/JS/HTML.
- **FR-36**: Upload media CMS divalidasi (MIME, ukuran, dimensi).

## Non-Functional Requirements
- **NFR-1**: Responsif di 1440px, 1024px, 390px, 360px tanpa horizontal overflow.
- **NFR-2**: Aksesibilitas keyboard; skip link; aria-label pada navigasi.
- **NFR-3**: SEO metadata (title, description, OG, Twitter) per halaman.
- **NFR-4**: Locale `id`, `en`, `ms-MY` dengan paritas kunci terjaga.
- **NFR-5**: Route collision checks lulus; `/api/v1` dan webhook tidak terganggu.
- **NFR-6**: Fail-closed pada semua boundary tenant/domain.
- **NFR-7**: Audit log untuk setiap publish/restore.

## Constraints
- **Technical**: Satu aplikasi Laravel; central DB untuk identitas User, membership, registry tenant/slug, domain terverifikasi, dan CMS platform; tenant DB untuk CMS klinik, data klinis, dan booking request. Filament/Livewire existing dipakai untuk panel; Blade untuk frontpage publik.
- **Business**: Brand ≠ Tenant; domain kustom perlu verifikasi DNS+TLS di luar aplikasi sebelum dinyatakan aktif; LBA bukan diagnosis.
- **Dependencies**: `TenantDomainResolver` existing; `PatientAccountLinker` existing; autentikasi Filament existing; `User` model central; `AppointmentScheduler`/`BranchAvailability` existing; `TenantDatabaseManager` existing.

## Assumptions
- Konten website **platform** (LBA Cloud) disimpan di central DB agar `/` anonim tidak perlu membuka koneksi tenant DB.
- Konten website **klinik** disimpan di DB tenant masing-masing; frontpage klinik menginisialisasi koneksi tenant DB via mekanisme existing sebelum membaca konten published.
- Patient self-registration membuat `User` central + `TenantMembership` + record `Patient` minimal (self-registered/unverified) di tenant DB, **tanpa transaksi lintas koneksi** — provisioning idempotent dan retry-safe.
- Booking pengunjung menyimpan `BookingRequest` di tenant DB (status pending_verification); setelah verifikasi email + login/registrasi, permintaan dikonversi ke `Appointment` via `AppointmentScheduler` dengan validasi slot ulang.
- Clinic website editor menggunakan TailAdmin (sama seperti panel Clinic existing) di route `clinic/workspace/site/*`.

## Acceptance Criteria

### AC-1: Principal frontpage dapat diedit dan dipublikasikan
- **Type**: `rule`
- **Given**: Principal Super Admin login di panel Principal dengan `platform.site.manage`
- **When**: Mengedit hero, layanan, dan SEO lalu klik Publish
- **Then**: Halaman `/` menampilkan konten baru; hanya published version yang terlihat publik; draft tidak muncul
- **Pass Condition**: GET `/` menampilkan teks yang dipublikasikan; GET draft preview hanya untuk actor authorized
- **Evidence**: Test feature + browser screenshot

### AC-2: Landing existing jadi fallback
- **Type**: `rule`
- **Given**: Belum ada konfigurasi CMS published di central DB
- **When**: Mengakses `/`
- **Then**: `landing.blade.php` existing ditampilkan
- **Pass Condition**: View `landing` ter-render
- **Evidence**: Test feature

### AC-3: Tenant frontpage di URL fallback (baca dari DB tenant)
- **Type**: `rule`
- **Given**: Tenant aktif dengan slug `klinik-nubiom-jakarta` dengan konten published di DB tenant
- **When**: Mengakses `/klinik/klinik-nubiom-jakarta`
- **Then**: Frontpage tenant ditampilkan dengan identitas klinik tersebut, dibaca dari DB tenant
- **Pass Condition**: Response 200 berisi nama tenant; koneksi tenant DB terinisialisasi
- **Evidence**: Test feature

### AC-4: Dua tenant dengan Brand sama punya konten, media, revisi berbeda
- **Type**: `rule`
- **Given**: Tenant A & B keduanya pakai Brand NuBiom
- **When**: Tenant A mengubah hero dan mengunggah media, lalu publish
- **Then**: Hero, media, dan riwayat revisi tenant B tidak berubah
- **Pass Condition**: Konten tenant A ≠ tenant B di DB tenant masing-masing; media A tidak muncul di B
- **Evidence**: Test feature dengan 2 tenant sintetis

### AC-5: Clinic Admin A tidak bisa edit tenant B
- **Type**: `rule`
- **Given**: Clinic Admin tenant A
- **When**: Mencoba submit ke endpoint edit tenant B (forged ID, Livewire payload, direct endpoint)
- **Then**: Ditolak 403
- **Pass Condition**: Response 403; konten tenant B tidak berubah
- **Evidence**: Test feature

### AC-6: Tenant suspended/domain unverified fail closed
- **Type**: `rule`
- **Given**: Tenant suspended atau domain unverified
- **When**: Mengakses frontpage via domain atau slug
- **Then**: Tidak dilayani (404 atau redirect aman)
- **Pass Condition**: Response bukan 200 dengan konten tenant
- **Evidence**: Test feature

### AC-7: Host spoofing tidak bisa pilih tenant lain
- **Type**: `rule`
- **Given**: Domain tenant A terverifikasi
- **When**: Request dengan Host header tenant B tapi tenant B tidak punya domain tersebut
- **Then**: Tidak menyajikan konten tenant B
- **Pass Condition**: Resolver mengembalikan null → 404/redirect ke host utama
- **Evidence**: Test feature

### AC-8: Draft tidak muncul di publik (platform & tenant)
- **Type**: `rule`
- **Given**: Draft tersimpan, belum ada published
- **When**: GET frontpage publik
- **Then**: Tidak menampilkan draft
- **Pass Condition**: Konten draft tidak ada di response
- **Evidence**: Test feature

### AC-9: Restore revisi bekerja
- **Type**: `rule`
- **Given**: Ada 2 revisi (v1 published, v2 draft)
- **When**: Restore ke v1 lalu publish
- **Then**: Konten kembali ke v1; audit tercatat
- **Pass Condition**: Konten published == v1; ada audit log
- **Evidence**: Test feature

### AC-10: Public asset CMS tidak bisa akses private medical storage
- **Type**: `rule`
- **Given**: File private di storage patient (disk `lba`/`lba-reports`)
- **When**: Mengakses via URL public asset CMS
- **Then**: 403/404
- **Pass Condition**: Tidak ada path traversal; file private tidak dapat diunduh
- **Evidence**: Test feature

### AC-11: Tenant DB tanpa konten → default template
- **Type**: `rule`
- **Given**: Tenant aktif, DB dapat diakses, belum ada CMS published
- **When**: Mengakses `/klinik/{slug}`
- **Then**: Default template klinik ditampilkan (nama tenant, cabang aktif, CTA)
- **Pass Condition**: Response 200 berisi nama tenant + default sections
- **Evidence**: Test feature

### AC-12: Tenant DB tidak dapat diakses → pesan layanan
- **Type**: `rule`
- **Given**: Tenant aktif tapi DB tenant gagal diakses
- **When**: Mengakses `/klinik/{slug}`
- **Then**: Pesan "layanan sementara tidak tersedia" tanpa detail koneksi
- **Pass Condition**: Response 503 dengan pesan generik; tidak ada stack trace/detail kredensial
- **Evidence**: Test feature (mock DB failure)

### AC-13: Registrasi pasien masuk tenant yang benar (cross-DB)
- **Type**: `rule`
- **Given**: Frontpage tenant A
- **When**: Pasien mendaftar
- **Then**: `User` central + membership tenant A + `Patient` record (self-registered/unverified) di DB tenant A
- **Pass Condition**: Membership hanya di tenant A; patient record ada di tenant A DB; bukan di central DB
- **Evidence**: Test feature

### AC-14: Registrasi tidak mengklaim rekam medis existing
- **Type**: `rule`
- **Given**: Ada patient record existing dengan email sama
- **When**: Pasien baru mendaftar dengan email itu
- **Then**: Tidak otomatis bind ke record existing; record baru dibuat unverified
- **Pass Condition**: `patient.user_id` tetap null untuk record existing; tidak ada pencocokan email/HP
- **Evidence**: Test feature

### AC-15: Email existing tidak buat duplikat
- **Type**: `rule`
- **Given**: User dengan email X sudah ada
- **When**: Registrasi dengan email X
- **Then**: Tidak membuat user baru; diarahkan ke login dengan pesan generik
- **Pass Condition**: Jumlah user tidak bertambah; respons tidak mengungkap keberadaan akun
- **Evidence**: Test feature

### AC-16: Provisioning idempotent & retry-safe (tanpa transaksi lintas DB)
- **Type**: `rule`
- **Given**: Submit ganda atau retry saat provisioning (User sudah dibuat tapi Patient belum)
- **When**: Submit ulang dengan email yang sama
- **Then**: Tidak membuat User/membership/Patient duplikat; data konsisten; portal tidak terbuka setengah jadi
- **Pass Condition**: Tidak ada duplikat row; Patient record terbuat pada retry; email_verified_at jadi syarat portal
- **Evidence**: Test feature (simulasi partial failure + retry)

### AC-17: Login staf tenant A tidak bisa akses tenant B
- **Type**: `rule`
- **Given**: Staf hanya member tenant A
- **When**: Login lalu coba akses konteks tenant B
- **Then**: Ditolak
- **Pass Condition**: 403 atau redirect; tidak ada data tenant B
- **Evidence**: Test feature

### AC-18: Principal MFA tetap bekerja
- **Type**: `rule`
- **Given**: Principal dengan MFA aktif
- **When**: Login principal
- **Then**: MFA diminta sebelum akses panel
- **Pass Condition**: Tidak bisa akses `/principal` tanpa MFA
- **Evidence**: Test feature existing tidak regresi

### AC-19: Existing login/booking/exam/portal tidak rusak
- **Type**: `rule`
- **Given**: Semua fitur existing
- **When**: Menjalankan suite test
- **Then**: Semua lulus
- **Pass Condition**: `php artisan test` hijau
- **Evidence**: Test output

### AC-20: Route `/api/v1` & webhook tidak terganggu
- **Type**: `rule`
- **Given**: Domain-aware routing aktif
- **When**: Request ke `/api/v1/*` dan webhook
- **Then**: Tetap berfungsi normal
- **Pass Condition**: Response sesuai tanpa redirect domain
- **Evidence**: Test feature + route list check

### AC-21: Responsif & aksesibel
- **Type**: `rubric`
- **Dimension**: Kualitas responsif & aksesibilitas frontpage
- **Scale**: 1-5
- **Anchors**: 1 = overflow horizontal di mobile; 3 = layout rapi tapi belum keyboard-accessible; 5 = 1440/1024/390/360 tanpa overflow, skip link, aria-label, keyboard navigable
- **Pass Threshold**: >= 4
- **Evidence**: Browser screenshot 4 viewport + axe/keyboard check

### AC-22: SEO & multibahasa
- **Type**: `rubric`
- **Dimension**: Kelengkapan SEO metadata & paritas locale
- **Scale**: 1-5
- **Anchors**: 1 = tidak ada meta; 3 = title+description ada tapi OG/ms-MY belum; 5 = title/description/OG/Twitter lengkap, id/en/ms-MY paritas, canonical URL
- **Pass Threshold**: >= 4
- **Evidence**: `php artisan lba:localization:audit --strict` lulus + view source check

### AC-23: Security editor CMS
- **Type**: `rule`
- **Given**: Input berisi `<script>` atau PHP
- **When**: Disimpan lalu dirender
- **Then**: Disesanitasi; tidak mengeksekusi
- **Pass Condition**: Tidak ada script execution di output
- **Evidence**: Test feature XSS

### AC-24: Clinic Admin tidak bisa mengelola domain
- **Type**: `rule`
- **Given**: Clinic Admin atau Tenant Owner
- **When**: Mencoba menambah/mengubah/menghapus domain (via UI atau direct endpoint)
- **Then**: Ditolak 403
- **Pass Condition**: Response 403; domain tidak berubah
- **Evidence**: Test feature (forged endpoint + UI check)

### AC-25: Booking pengunjung → verifikasi email → lanjut di portal
- **Type**: `rule`
- **Given**: Pengunjung (belum login) di frontpage tenant A
- **When**: Mengisi form booking (nama, email, cabang, slot, layanan) lalu submit
- **Then**: `BookingRequest` tersimpan di tenant A DB dengan status `pending_verification`; email verifikasi terkirim; pesan "belum terkonfirmasi"
- **Pass Condition**: Ada `booking_requests` row di tenant A; status pending; email terkirim
- **Evidence**: Test feature

### AC-26: Setelah verifikasi email, booking dilanjutkan tanpa input ulang
- **Type**: `rule`
- **Given**: `BookingRequest` pending_verification, user klik link verifikasi lalu login/daftar
- **When**: Masuk portal pasien
- **Then**: Permintaan booking yang sama ditampilkan untuk dikonfirmasi; data (cabang, slot, layanan) tidak perlu diinput ulang
- **Pass Condition**: `BookingRequest` ter-resolve ke user yang benar; slot divalidasi ulang sebelum konfirmasi
- **Evidence**: Test feature

### AC-27: Booking slot conflict & retry tidak ganda
- **Type**: `rule`
- **Given**: Slot sudah di-booking user lain, atau retry konfirmasi
- **When**: Konfirmasi booking di portal
- **Then**: Slot conflict ditolak (slot_unavailable); retry tidak membuat appointment ganda
- **Pass Condition**: `AppointmentConflictException` atau tidak ada appointment duplikat
- **Evidence**: Test feature

### AC-28: Pasien login (dengan membership) → booking diarahkan ke portal
- **Type**: `rule`
- **Given**: Pasien sudah login dengan membership tenant A
- **When**: Klik Booking di frontpage tenant A
- **Then**: Diarahkan ke portal pasien dengan konteks tenant A dan alur booking existing
- **Pass Condition**: Redirect ke `/patient` dengan tenant context benar
- **Evidence**: Test feature

## Decisions (Closed Questions)
- **Q1 (Verifikasi)**: Verifikasi akun pasien cukup melalui **email** saja. Nomor HP opsional, tidak dipakai untuk login/verifikasi.
- **Q2 (Domain)**: **Hanya Principal Super Admin** yang dapat menambah/mengubah/menyetujui/menghapus domain. Clinic Admin/Tenant Owner hanya mengelola konten website.
- **Q3 (Booking)**: Booking dari frontpage klinik diimplementasikan sebagai **form publik** (bukan sekadar link). Pengunjung mengisi form, verifikasi email, lalu melanjutkan di portal pasien.

## Verification Matrix
| Pemeriksaan | Bukti di Tes | Perlu UAT Hosting |
|---|---|---|
| Editor platform draft/publish | Test feature | Screenshot browser |
| Fallback landing.blade.php | Test feature | — |
| Frontpage tenant baca dari DB tenant | Test feature | — |
| 2 tenant NuBiom terisolasi (konten/media/revisi) | Test feature | — |
| Clinic Admin tidak bisa edit tenant B | Test feature | — |
| Domain unverified/suspended fail closed | Test feature | — |
| Host spoofing ditolak | Test feature | — |
| Tenant DB tanpa konten → default template | Test feature | — |
| Tenant DB gagal → pesan layanan | Test feature (mock) | UAT koneksi nyata |
| Registrasi pasien cross-DB | Test feature | — |
| Tidak klaim rekam medis existing | Test feature | — |
| Email existing tidak duplikat | Test feature | — |
| Provisioning idempotent/retry | Test feature | — |
| Email verification gating portal | Test feature | — |
| Login staf/dokter per tenant | Test feature | — |
| Principal MFA | Test feature | — |
| Existing portal/API/webhook tidak regresi | Test suite | — |
| Clinic Admin tidak kelola domain | Test feature | — |
| Booking pengunjung form + verifikasi email | Test feature | UAT email nyata |
| Booking lanjut di portal tanpa input ulang | Test feature | — |
| Slot conflict & retry tidak ganda | Test feature | — |
| Pasien login → booking ke portal | Test feature | — |
| Media CMS terpisah dari storage medis | Test feature | — |
| Responsif 4 viewport | — | Browser screenshot |
| SEO & locale paritas | localization audit | — |
