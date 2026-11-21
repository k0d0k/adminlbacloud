# Website CMS Multi-Tenant LBA Cloud — Implementation Plan

## Task 1: CMS data models (central platform + tenant clinic)
- **Status**: `pending`
- **Priority**: high
- **Depends On**: None
- **Description**:
  - **Central DB** (platform website): migrasi `site_pages` (id, owner_type enum[platform], owner_id null, slug, status draft/published, current_revision_id, created_by, timestamps), `site_page_revisions` (id, page_id, content JSON, locale, title, seo JSON, created_by, created_at), `site_media` (id, owner_type, owner_id null, disk, path, mime_type, size_bytes, alt_text, created_by). Owner_type `platform` (owner_id null) untuk website LBA Cloud.
  - **Tenant DB** (clinic website): migrasi **sama** di `database/migrations/tenant/` (tabel `site_pages`, `site_page_revisions`, `site_media`) dengan `owner_type=tenant` dan `owner_id` tidak dipakai (tenant = DB itu sendiri). Tambahkan kolom `settings JSON` untuk pengaturan tampilan (warna, tipografi, logo).
  - Model `SitePage`, `SitePageRevision`, `SiteMedia` di `app/Platform/Site/`. Model untuk tenant memakai `TenantModel` (fail-closed).
  - Relasi: `SitePage->revisions()`, `SitePage->currentRevision()`, `SitePage->media()`.
  - Constraint unik: satu page per owner (central: owner_type+owner_id; tenant: cukup satu row per DB).
- **Acceptance Criteria Addressed**: AC-1, AC-2, AC-3, AC-4, AC-8, AC-9, AC-11, AC-23
- **Test Requirements**:
  - `rule` TR-1.1: Migrasi central `site_pages`, `site_page_revisions`, `site_media` berhasil dijalankan. Evidence: `php artisan migrate --force` + `Schema::hasTable()`.
  - `rule` TR-1.2: Migrasi tenant `site_pages` dll berhasil dijalankan via `lba:tenant:migrate:registered` untuk tenant existing. Evidence: test migrate + `Schema::hasTable()` di koneksi tenant.
  - `rule` TR-1.3: Satu tenant hanya punya satu `site_page`. Evidence: test insert duplikat gagal.
  - `rule` TR-1.4: Model tenant `SitePage` gagal closed tanpa konteks tenant. Evidence: test `TenantContextMissingException`.
- **Notes**: Konten platform di central DB; konten klinik di DB tenant. Tidak ada duplikasi konten klinik di central.

## Task 2: Service SiteContentPublisher (multi-connection)
- **Status**: `pending`
- **Priority**: high
- **Depends On**: Task 1
- **Description**:
  - Service `SiteContentPublisher` di `app/Platform/Site/`: `saveDraft(owner, locale, content, actor)`, `publish(owner, locale, actor)`, `getPublished(owner, locale)`, `getDraft(owner, locale)`, `restoreRevision(revisionId, actor)`, `listRevisions(owner)`.
  - Multi-connection: untuk platform pakai koneksi default (central); untuk tenant pakai koneksi tenant (resolved via `ResolvesTenantDatabase`). Interface `SiteContentRepository` dengan implementasi `CentralSiteRepository` dan `TenantSiteRepository`.
  - Setiap publish membuat revision baru; `current_revision_id` menunjuk revision yang aktif.
  - Audit ke `ModuleAuditLog` (platform: `platform.site`; tenant: `tenant.site` di tenant DB audit log), action `published`/`restored`/`drafted`.
  - `getPublished` gagal-closed: return null kalau tidak ada published.
- **Acceptance Criteria Addressed**: AC-1, AC-2, AC-8, AC-9
- **Test Requirements**:
  - `rule` TR-2.1: `saveDraft` lalu `getPublished` mengembalikan null (draft tidak muncul). Evidence: test.
  - `rule` TR-2.2: `publish` meng-update `current_revision_id`; `getPublished` mengembalikan konten. Evidence: test.
  - `rule` TR-2.3: `restoreRevision` membuat revision baru dengan konten revisi lama; audit tercatat. Evidence: test assert count revision + audit log.
  - `rule` TR-2.4: Multibahasa: content per locale terpisah; publish locale id tidak mempengaruhi en. Evidence: test.
  - `rule` TR-2.5: Tenant repository gagal closed tanpa konteks tenant. Evidence: test.
- **Notes**: Content adalah array section (type, data, visible, order).

## Task 3: Principal website editor (TailAdmin) + permission
- **Status**: `pending`
- **Priority**: high
- **Depends On**: Task 2
- **Description**:
  - Tambah permission `PLATFORM_SITE_MANAGE = 'platform.site.manage'` ke `PermissionKey` + daftar di `ALL`. Tambah scope platform di `RbacCatalog::permissionScopes()`. Beri ke role principal admin di seed.
  - Controller `Principal\SiteController`: `edit`, `updateDraft`, `publish`, `revisions`, `restore`, `preview`.
  - Route di `routes/principal.php`: group `site.*` digerbangi `can:platform.site.manage`.
  - View `resources/views/principal/site/edit.blade.php`: section-based editor (hero, services, features, workflow, faq, contact, seo), draft/publish/preview buttons, locale switcher id/en/ms-MY.
  - Tambah menu "Website & Frontpage" di sidebar principal.
  - Rich text pakai textarea dengan sanitasi (strip tags kecuali tag aman), tidak ada WYSIWYG arbitrary.
- **Acceptance Criteria Addressed**: AC-1, AC-2, AC-8, AC-9, AC-23
- **Test Requirements**:
  - `rule` TR-3.1: Principal tanpa `platform.site.manage` dapat 403. Evidence: test.
  - `rule` TR-3.2: Submit draft lalu publish → `/` menampilkan konten published. Evidence: test GET `/`.
  - `rule` TR-3.3: Input `<script>alert(1)</script>` disanitasi, tidak dieksekusi di output. Evidence: test assert response tidak mengandung `<script>`.
  - `rule` TR-3.4: Preview draft hanya bisa diakses actor authorized; tamu dapat 403. Evidence: test.
- **Notes**: Editor section-based: array section dengan type (hero/text/features/faq/contact) dan data fields.

## Task 4: Render frontpage Principal dari CMS
- **Status**: `pending`
- **Priority**: high
- **Depends On**: Task 3
- **Description**:
  - Ubah route `/` di `routes/web.php` menjadi controller `PublicSiteController@showPlatform`.
  - `PublicSiteController@showPlatform`: ambil published site page platform (central); kalau ada → render `site/platform.blade.php` dari content; kalau tidak ada → `return view('landing')` (fallback existing).
  - View `resources/views/site/platform.blade.php`: render section secara dinamis dari content JSON, pakai CSS existing (`lba-*`).
  - SEO meta dari content (title, description, OG image).
- **Acceptance Criteria Addressed**: AC-1, AC-2, AC-22
- **Test Requirements**:
  - `rule` TR-4.1: Tanpa CMS published → `/` render `landing`. Evidence: test assert view is `landing`.
  - `rule` TR-4.2: Dengan CMS published → `/` render konten CMS. Evidence: test assert response berisi teks published.
  - `rule` TR-4.3: SEO meta (og:title, description) ada di response. Evidence: test assert response content.
- **Notes**: Fallback ke `landing.blade.php` existing wajib dipertahankan.

## Task 5: Tenant frontpage route + tenant DB connection + graceful failure
- **Status**: `pending`
- **Priority**: high
- **Depends On**: Task 2
- **Description**:
  - Route publik `/klinik/{slug}` → `PublicSiteController@showTenant` di `routes/web.php` (sebelum require principal/clinic agar tidak tertimpa).
  - `showTenant($slug)`:
    1. Cari `Tenant` aktif by slug (central DB); kalau tidak ada/suspended/soft-deleted → 404.
    2. Inisialisasi koneksi tenant DB via `TenantDatabaseManager->configure($tenantId)` (mekanisme existing).
    3. Baca published site page dari DB tenant via `TenantSiteRepository`.
    4. Kalau ada published → render `site/tenant.blade.php` dari content.
    5. Kalau tidak ada published tapi DB bisa diakses → render default template `site/tenant-default.blade.php`.
    6. Kalau DB gagal diakses → response 503 dengan view `site/service-unavailable.blade.php` (pesan generik, tanpa detail koneksi).
    7. `finally`: `TenantDatabaseManager->forget()` untuk purge koneksi (hindari leak).
  - Default template tenant: header (logo tenant/brand), hero, tentang, layanan publik (dari `branches` aktif), lokasi cabang aktif, FAQ default, CTA daftar/login/booking, footer. Hanya data publik tenant.
  - Tombol CTA: Daftar Pasien → `/klinik/{slug}/daftar`, Login Pasien → `/patient/login?tenant={slug}`, Login Staf → `/login?tenant={slug}`, Booking → `/klinik/{slug}/booking`.
- **Acceptance Criteria Addressed**: AC-3, AC-4, AC-6, AC-11, AC-12, AC-21
- **Test Requirements**:
  - `rule` TR-5.1: GET `/klinik/{slug-aktif}` dengan CMS published → 200 berisi nama tenant + konten published. Evidence: test.
  - `rule` TR-5.2: GET `/klinik/{slug-suspended}` → 404. Evidence: test.
  - `rule` TR-5.3: GET `/klinik/{slug-tidak-ada}` → 404. Evidence: test.
  - `rule` TR-5.4: Tanpa CMS tenant (DB bisa diakses) → default template tampil. Evidence: test assert response berisi default sections.
  - `rule` TR-5.5: DB tenant gagal diakses → 503 pesan generik tanpa detail. Evidence: test mock DB failure.
  - `rubric` TR-5.6: Responsif 1440/1024/390/360; scale 1-5; threshold >=4; evidence screenshot.
- **Notes**: Default template memakai data publik dari tenant (nama, brand, cabang aktif) tanpa membuka data klinis. Koneksi tenant di-purge di `finally`.

## Task 6: Clinic website editor + tenant.site.manage permission
- **Status**: `pending`
- **Priority**: high
- **Depends On**: Task 2, Task 5
- **Description**:
  - Tambah permission `TENANT_SITE_MANAGE = 'tenant.site.manage'` ke `PermissionKey` + `ALL`. Scope tenant (default). Beri ke role `TENANT_OWNER` dan `TENANT_ADMIN` di seed.
  - Controller `Clinic\SiteController`: `edit`, `updateDraft`, `publish`, `revisions`, `restore`, `preview`. Semua route di `routes/clinic.php` under `clinic/workspace/site/*`.
  - Middleware: `EnsureClinicTenantContext` + cek `tenant.site.manage` di controller (server-side, bukan hanya UI).
  - View `resources/views/clinic/site/edit.blade.php`: section editor khusus klinik (hero, tentang, layanan, lokasi, jadwal publik, profil tenaga ahli yang disetujui, FAQ, kontak/WA, SEO).
  - Tambah menu "Website Klinik" di navigasi Clinic TailAdmin.
- **Acceptance Criteria Addressed**: AC-4, AC-5, AC-6, AC-8, AC-9
- **Test Requirements**:
  - `rule` TR-6.1: Clinic Admin tenant A bisa edit & publish site tenant A. Evidence: test.
  - `rule` TR-6.2: Staf tanpa `tenant.site.manage` → 403. Evidence: test.
  - `rule` TR-6.3: Clinic Admin tenant A submit ke endpoint tenant B (forged ID) → 403; konten B tidak berubah. Evidence: test.
  - `rule` TR-6.4: Pasien mencoba akses editor → 403/redirect. Evidence: test.
- **Notes**: Izin diperiksa ulang di server, bukan hanya dari UI.

## Task 7: Custom domain routing + Principal-only domain management
- **Status**: `pending`
- **Priority**: high
- **Depends On**: Task 5
- **Description**:
  - Middleware `ResolveTenantDomain` di jalur web awal: cek `Host` header via `TenantDomainResolver->resolveHost()`.
  - Kalau resolved tenant: set tenant context untuk frontpage. Route `/` pada custom domain → `PublicSiteController@showTenant`.
  - Reserved hosts: APP_URL host, semua route `/principal`, `/clinic`, `/patient`, `/login`, `/api`, `/klinik/*`, `/.well-known`. Host yang reserved → abaikan domain resolution.
  - Host spoofing: validator `TenantDomain::normalizeDomain` + `isValidDomain`; host tidak valid → abaikan.
  - Canonical URL: redirect non-canonical hanya untuk domain terverifikasi.
  - **Domain management Principal-only**: Pastikan endpoint CRUD domain (jika ada) hanya bisa diakses Principal Super Admin. Jika ada UI domain di panel Clinic, sembunyikan. Tambahkan test bahwa Clinic Admin tidak bisa mengakses endpoint domain (403).
  - Login dari custom domain: jika perlu diteruskan ke host utama, gunakan `config('app.url')` (tepercaya) dan pertahankan `tenant` query param tervalidasi.
- **Acceptance Criteria Addressed**: AC-6, AC-7, AC-20, AC-24
- **Test Requirements**:
  - `rule` TR-7.1: Request dengan Host = domain tenant terverifikasi → `/` render frontpage tenant. Evidence: test.
  - `rule` TR-7.2: Host = domain unverified → tidak render tenant (404/redirect ke APP_URL). Evidence: test.
  - `rule` TR-7.3: Host spoofing (domain tidak terdaftar) → resolve null → fallback platform. Evidence: test.
  - `rule` TR-7.4: `/api/v1/*` dan `/login` pada custom domain tetap berfungsi (tidak di-redirect). Evidence: test.
  - `rule` TR-7.5: Clinic Admin mencoba akses endpoint domain CRUD → 403. Evidence: test.
  - `rule` TR-7.6: Tenant suspended via domain → tidak dilayani. Evidence: test.
- **Notes**: Domain resolution hanya untuk frontpage publik; tidak pernah membuka DB klinis atau memberikan otorisasi. Domain management hanya Principal.

## Task 8: Patient self-registration (cross-DB, idempotent, email verification)
- **Status**: `pending`
- **Priority**: high
- **Depends On**: Task 5
- **Description**:
  - Route `/klinik/{slug}/daftar` (GET form, POST submit) → `PatientRegistrationController`.
  - Form: nama, email, HP (opsional), password, konfirmasi, persetujuan privasi.
  - Service `PatientSelfRegistration`:
    1. Validasi input + rate limit (`throttle:5,1`).
    2. Cek tenant aktif; kalau suspended → 404.
    3. Cek email existing di `users`:
       - Kalau ada dan user **belum login**: redirect ke login dengan pesan generik (tidak buat duplikat, tidak ungkap keberadaan akun).
       - Kalau ada dan user **sudah login** tapi belum punya membership di tenant ini: tampilkan konfirmasi untuk menambah membership (aksi eksplisit tervalidasi). Jangan ubah role existing.
    4. Provisioning (idempotent, tanpa transaksi lintas DB):
       - **Step A (central)**: buat `User` (role `PATIENT_CUSTOMER`, `is_active=true`, `email_verified_at=null`) — atau pakai existing jika login.
       - **Step B (central)**: buat `TenantMembership` (status active, role patient) — skip jika sudah ada.
       - **Step C (tenant DB)**: buat `Patient` record (status `self_registered`/unverified, `user_id` = user id, nama, email, HP opsional) — skip jika sudah ada `user_id` yang sama.
    5. Kirim email verifikasi (pakai pola `AccountEmailChanger` signed link atau perluasan `VerifyAccountEmail`). Set `email_verified_at` hanya setelah verifikasi.
    6. **Portal access gating**: `EnsurePatientTenantContext` atau middleware memastikan `email_verified_at` tidak null sebelum membuka portal. Jika belum verifikasi → redirect ke halaman "cek email".
  - Tambah status `self_registered` ke `Patient` model (atau kolom `registration_source`). `user_id` di-set ke akun self-registered. Tidak mencari record existing by email/HP.
  - Idempotency: setiap step cek dulu apakah sudah ada sebelum insert. Double submit tidak membuat duplikat.
- **Acceptance Criteria Addressed**: AC-13, AC-14, AC-15, AC-16
- **Test Requirements**:
  - `rule` TR-8.1: Registrasi sukses → user + membership tenant + patient record ada. Evidence: test assert DB.
  - `rule` TR-8.2: Email existing (user belum login) → tidak buat user baru, redirect login. Evidence: test assert count user tidak bertambah.
  - `rule` TR-8.3: Patient record existing dengan email sama → `user_id` tidak berubah (tidak di-claim). Evidence: test.
  - `rule` TR-8.4: Rate limit: >5 request/menit → 429. Evidence: test.
  - `rule` TR-8.5: Tenant suspended → registrasi ditolak. Evidence: test.
  - `rule` TR-8.6: Partial failure (User dibuat, Patient gagal) → retry berhasil tanpa duplikat; portal tidak terbuka sebelum verifikasi. Evidence: test simulasi.
  - `rule` TR-8.7: User sudah login tanpa membership → bisa tambah membership via aksi eksplisit; role tidak berubah. Evidence: test.
  - `rule` TR-8.8: Email belum diverifikasi → akses portal ditolak/redirect. Evidence: test.
- **Notes**: Tidak ada `DB::transaction` lintas koneksi. Tiap step idempotent. `Patient.user_id` di-set ke akun self-registered.

## Task 9: Patient login dari frontpage klinik
- **Status**: `pending`
- **Priority**: high
- **Depends On**: Task 5
- **Description**:
  - Tombol "Login Pasien" di `/klinik/{slug}` → redirect ke `/patient/login?tenant={slug}`.
  - Setelah login via Filament patient panel, `EnsurePatientTenantContext` existing menangani: kalau single membership → auto-select; kalau multiple → pilih klinik.
  - Pastikan `tenant` query param dipakai untuk pre-select context (jika membership valid).
  - Tidak mengubah alur auth existing; hanya menambahkan tenant context hint.
- **Acceptance Criteria Addressed**: AC-13, AC-17
- **Test Requirements**:
  - `rule` TR-9.1: Pasien dengan membership tenant A login dari frontpage A → masuk portal tenant A. Evidence: test.
  - `rule` TR-9.2: Pasien tanpa membership tenant A → 403 setelah login. Evidence: test.
  - `rule` TR-9.3: Pasien dengan multiple membership → diarahkan ke pilih klinik. Evidence: test.
- **Notes**: Reuse `EnsurePatientTenantContext` existing; tidak buat auth baru.

## Task 10: Staff login dari frontpage klinik
- **Status**: `pending`
- **Priority**: high
- **Depends On**: Task 5
- **Description**:
  - Tombol "Login Staf" → `/login?tenant={slug}`.
  - Setelah login, `EnsureClinicTenantContext` existing menangani: single membership → auto; multiple → pilih.
  - Principal tetap pakai MFA dan jalur platform.
- **Acceptance Criteria Addressed**: AC-17, AC-18
- **Test Requirements**:
  - `rule` TR-10.1: Staf tenant A login dari frontpage A → masuk clinic panel tenant A. Evidence: test.
  - `rule` TR-10.2: Staf tanpa membership tenant A → 403. Evidence: test.
  - `rule` TR-10.3: Principal login → MFA diminta, masuk principal panel. Evidence: test (existing tidak regresi).
- **Notes**: Reuse `/login` existing; tidak buat auth baru.

## Task 11: Visitor booking form + email verification + portal continuation + slot conflict
- **Status**: `pending`
- **Priority**: high
- **Depends On**: Task 5, Task 8
- **Description**:
  - **Migrasi tenant**: tabel `booking_requests` (id ULID, full_name, email, phone nullable, branch_id, service_type, scheduled_at, status enum[pending_verification, verified, converted, expired, cancelled], verification_token hash, verification_expires_at, privacy_consent_at, appointment_id nullable, created_at, timestamps). Index unik pada (email, branch_id, scheduled_at) untuk cegah duplikat.
  - **Model** `BookingRequest` (extends `TenantModel`).
  - **Route** `/klinik/{slug}/booking` (GET form, POST submit) → `VisitorBookingController`.
  - **Form** (public): nama, email, HP (opsional), pilih cabang aktif, pilih layanan (dari `service_locations`/modul booking existing), pilih slot tanggal+waktu (dari `BranchAvailability::slotsForDate`). Persetujuan privasi wajib.
  - **Submit**:
    1. Validasi input + rate limit.
    2. Resolve tenant aktif, inisialisasi koneksi tenant DB.
    3. Cek slot masih tersedia via `BranchAvailability` (validasi awal).
    4. Buat `BookingRequest` status `pending_verification` dengan token verifikasi (random 64-char, disimpan hash).
    5. Kirim email verifikasi (signed link, pola `VerifyAccountEmail`).
    6. Response: "Pengajuan booking Anda menunggu verifikasi email. Ini belum menjadi jadwal terkonfirmasi."
  - **Verifikasi email** (`/klinik/{slug}/booking/verify/{token}`):
    1. Validasi token + expiry.
    2. Set status `verified`.
    3. Jika user sudah punya akun → redirect login (dengan booking request context). Jika belum → redirect registrasi (dengan booking request context).
    4. Context disimpan di session (signed) atau query param token.
  - **Lanjut di portal**:
    - Setelah login/registrasi + verifikasi email, user melihat `BookingRequest` yang pending untuk dikonfirmasi.
    - Saat konfirmasi: **validasi ulang slot** via `BranchAvailability` + buat `Appointment` via `AppointmentScheduler::create()` (atau `hold()` jika pakai hold). Set `BookingRequest.appointment_id` dan status `converted`.
    - Jika slot sudah penuh → error `slot_unavailable`, minta pilih slot lain.
    - Jika `BookingRequest` sudah `converted` (retry) → tidak buat appointment baru; redirect ke appointment yang sudah ada.
  - **Pasien sudah login**: tombol Booking di frontpage → redirect ke portal pasien (`/patient`) dengan tenant context, gunakan alur booking existing.
- **Acceptance Criteria Addressed**: AC-25, AC-26, AC-27, AC-28
- **Test Requirements**:
  - `rule` TR-11.1: Pengunjung submit booking → `BookingRequest` pending_verification di tenant DB; email verifikasi terkirim. Evidence: test.
  - `rule` TR-11.2: Klik link verifikasi → status jadi `verified`; redirect ke login/registrasi dengan context. Evidence: test.
  - `rule` TR-11.3: Setelah login, booking request muncul di portal; data (cabang, slot, layanan) tidak perlu diinput ulang. Evidence: test.
  - `rule` TR-11.4: Konfirmasi booking → slot divalidasi ulang; `Appointment` dibuat; `BookingRequest` converted. Evidence: test.
  - `rule` TR-11.5: Slot conflict → ditolak `slot_unavailable`; tidak ada appointment. Evidence: test.
  - `rule` TR-11.6: Retry konfirmasi → tidak buat appointment duplikat. Evidence: test.
  - `rule` TR-11.7: Pasien sudah login klik Booking → redirect ke portal dengan tenant context. Evidence: test.
  - `rule` TR-11.8: Form booking publik tidak meminta data medis. Evidence: test.
- **Notes**: Reuse `BranchAvailability` dan `AppointmentScheduler` existing. `BookingRequest` hanya menyimpan intent, bukan appointment final.

## Task 12: CMS media per-tenant storage, published-only public, isolated from medical storage
- **Status**: `pending`
- **Priority**: medium
- **Depends On**: Task 3, Task 6
- **Description**:
  - Disk baru `site-media` (atau pakai `public` disk dengan path prefix `site/`) untuk CMS media. **Tidak pernah** pakai disk `lba`, `lba-reports`, `account-photos` (storage medis).
  - **Platform media**: path `site/platform/{ulid}.{ext}` di disk `public`.
  - **Tenant media**: path `site/tenants/{tenantId}/{ulid}.{ext}` di disk `public`.
  - Endpoint upload media di Principal & Clinic editor: `POST .../site/media` → `SiteMediaController@store`.
  - Validasi: MIME whitelist (jpg/png/webp/svg/gif), max size (config), dimensi.
  - **Public access**: hanya media yang terasosiasi dengan revision published yang bisa diakses publik. Media draft hanya bisa di-preview oleh editor berwenang (signed URL atau controller auth).
  - Hapus media: cek ownership (platform/tenant).
  - Pastikan tidak ada path traversal (validasi nama file, pakai ULID).
- **Acceptance Criteria Addressed**: AC-10, AC-34, AC-35, AC-36
- **Test Requirements**:
  - `rule` TR-12.1: Upload image valid → tersimpan, URL public bisa diakses (jika published). Evidence: test.
  - `rule` TR-12.2: Upload non-image → ditolak. Evidence: test.
  - `rule` TR-12.3: Path traversal (`../private/`) ditolak. Evidence: test.
  - `rule` TR-12.4: Media tenant A tidak bisa dihapus oleh tenant B. Evidence: test.
  - `rule` TR-12.5: Media draft tidak bisa diakses publik (403); editor bisa preview. Evidence: test.
  - `rule` TR-12.6: File di storage medis (`lba` disk) tidak bisa diakses via URL CMS. Evidence: test.
- **Notes**: Disk `public`/`site-media` untuk CMS; disk private untuk patient — terpisah total.

## Task 13: Localization, tests, build, QA
- **Status**: `pending`
- **Priority**: high
- **Depends On**: Task 1–12
- **Description**:
  - Tambah kunci locale untuk semua UI baru di `lang/{id,en,ms-MY}/principal.php`, `clinic.php`, `site.php`, `booking.php`.
  - Jalankan `php artisan lba:localization:audit --strict`.
  - Jalankan `vendor/bin/pint --test`.
  - Jalankan `php artisan test` (full suite).
  - `npm run build`.
  - Browser screenshot: frontpage principal, editor principal, frontpage tenant, registrasi pasien, login klinik, form booking di 1440/1024/390/360.
  - Buat feature branch, commit, push, buat PR (tanpa merge/deploy).
- **Acceptance Criteria Addressed**: AC-18, AC-19, AC-20, AC-21, AC-22
- **Test Requirements**:
  - `rule` TR-13.1: `lba:localization:audit --strict` lulus. Evidence: command output.
  - `rule` TR-13.2: `pint --test` lulus. Evidence: command output.
  - `rule` TR-13.3: `php artisan test` semua lulus. Evidence: command output.
  - `rule` TR-13.4: `npm run build` sukses. Evidence: command output.
  - `rubric` TR-13.5: Screenshot 4 viewport tanpa overflow; scale 1-5; threshold >=4; evidence gambar.
- **Notes**: QA terakhir sebelum review. PR untuk review, tidak merge/deploy.
