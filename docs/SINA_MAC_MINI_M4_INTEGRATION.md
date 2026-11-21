# Menghubungkan SINA AI (Open WebUI di Mac Mini M4) ke LBA Cloud

Panduan ini menjelaskan cara menyambungkan SINA — asisten AI klinik LBA Cloud —
ke sebuah **Open WebUI yang berjalan di Mac Mini M4** pada jaringan lokal (LAN),
lalu mengatur **token & harga**, dan memakai **chat di sisi pasien maupun dokter**.

SINA memakai protokol **OpenAI-compatible** (`/chat/completions`). Open WebUI
memang menyediakan endpoint kompatibel-OpenAI di bawah path `/api`, jadi kita
cukup mengarahkan LBA Cloud ke Open WebUI — bukan ke Ollama langsung.

```
Pasien / Dokter  ──►  LBA Cloud (SINA chat)  ──►  Open WebUI (Mac Mini M4)  ──►  Ollama (LLM)
                          │
                          └─ dompet kredit + catatan pemakaian (central DB)
```

---

## 1. Siapkan Mac Mini M4

### 1.1 Ollama + model
`Open WebUI` menjalankan model melalui Ollama. Di Mac Mini M4:

```sh
brew install ollama
brew services start ollama          # Ollama mendengarkan di 127.0.0.1:11434
ollama pull qwen2.5:7b              # pilih model yang muat di memori M4
```

### 1.2 Open WebUI
Jalankan Open WebUI (Docker adalah cara paling sederhana) dan arahkan ke Ollama
host:

```sh
docker run -d --name open-webui \
  -p 3000:8080 \
  -e OLLAMA_BASE_URL=http://host.docker.internal:11434 \
  -e WEBUI_AUTH=true \
  -v open-webui:/app/backend/data \
  --restart always \
  ghcr.io/open-webui/open-webui:main
```

Buka `http://localhost:3000`, buat akun admin pertama.

### 1.3 Aktifkan akses LAN + buat API key
1. Buat agar Mac Mini punya alamat tetap di LAN, mis. `192.168.1.50` atau nama
   mDNS `mac-mini.local`.
2. Di Open WebUI: **Settings → Account → API Keys → Create new key**. Salin
   kuncinya (hanya ditampilkan sekali).
3. Pastikan port `3000` dapat diakses dari server LBA Cloud:
   ```sh
   curl -H "Authorization: Bearer <API_KEY>" http://mac-mini.local:3000/api/models
   ```
   Bila perintah ini mengembalikan daftar model, Open WebUI siap dipakai.

> Endpoint OpenAI-compatible Open WebUI adalah `http://<host>:3000/api`.
> LBA Cloud menambahkan `/chat/completions` sendiri, jadi **base URL** yang
> diisi adalah `http://mac-mini.local:3000/api`.

---

## 2. Daftarkan endpoint di LBA Cloud (Principal)

Masuk ke panel **Principal → Tata Kelola Platform → Integrasi SINA AI**
(`/principal/sina`).

1. Pada kartu **Endpoint Penyedia SINA**:
   - **Mitra** — pilih klinik/tenant yang memakai SINA.
   - **Penyedia** — pilih `OpenAI-compatible`.
   - **Base URL** — `http://mac-mini.local:3000/api`
     (atau `http://192.168.1.50:3000/api`).
   - **Model** — nama model, mis. `qwen2.5:7b` (harus sama dengan yang
     di-`pull` di Ollama / tersedia di Open WebUI).
   - **API Key** — tempel kunci dari langkah 1.3. Disimpan **terenkripsi** dan
     tidak pernah ditampilkan kembali.
   - Centang **Aktifkan SINA untuk mitra ini**, lalu **Simpan Konfigurasi**.

Endpoint boleh memakai **HTTP** selama host-nya loopback/jaringan lokal
(`localhost`, `*.local`, nama host tunggal, atau IP privat). Host publik wajib
**HTTPS**.

---

## 3. Atur token & harga

Pada kartu **Token & Harga** (halaman yang sama):

| Kolom | Arti | Nilai saran |
| --- | --- | --- |
| Aktifkan penagihan kredit SINA | menyalakan/mematikan penagihan | aktif |
| **Kredit per chat** | harga satu balasan SINA yang selesai | `1` |
| Kredit per 1.000 token | tarif cadangan bila token lebih besar | `1` |
| Satuan | nama unit kredit | `sina_credit` |

Dengan **Kredit per chat = 1**, maka **satu kali chat menghabiskan 1 token**:
nilai ini menjadi kredit yang dicadangkan sebelum balasan dijalankan, sekaligus
batas maksimum yang ditagih untuk balasan itu. Perubahan berlaku **segera**
tanpa deploy — konfigurasi disimpan di platform dan dibaca setiap turn.

---

## 4. Kredit dari paket & top-up

- **Kuota paket** — Principal → **Paket Langganan** (`/principal/subscriptions`),
  lalu isi **Kredit SINA per pasien per periode**. Kredit diberikan ke dompet
  SINA masing-masing pasien saat saldo diperiksa atau chat dimulai. Kredit yang
  belum digunakan tetap terbawa ke periode berikutnya.
- **Top-up pasien** — Klinik → **Pasien** → buka profil pasien, lalu gunakan
  bagian **Kredit SINA** untuk menambahkan kredit. Aksi ini hanya tampil bagi
  pengguna yang memiliki izin mengelola dompet.

Kuota paket adalah kredit SINA pasien, bukan token pemakaian model dan bukan
dompet kredit AI untuk analisis gambar LBA.

---

## 5. Chat di sisi pasien & dokter

- **Pasien** — membuka **Konsultasi & Chat SINA** di portal pasien, menyetujui
  persetujuan bantuan AI, lalu mengirim pesan. Setiap balasan SINA yang selesai
  menagih 1 kredit dari dompet SINA pasien tersebut.
- **Dokter / tenaga ahli** — buka antrean atau detail **Pemeriksaan** di panel
  klinik. Tombol **Chat SINA** tersedia untuk pemeriksaan manual, AI, maupun
  hybrid, dan membuka sesi yang difilter ke pasien tersebut. Transkrip serta
  balasan praktisi tersedia di dossier konsultasi pasien.

> Balasan praktisi hanya tersedia selama konsultasi belum selesai
> (status bukan `completed`/`cancelled`/`no_show`).

---

## 6. Catatan pemakaian

Kartu **Pemakaian SINA** menampilkan setiap balasan yang tercatat: mitra,
penyedia, model, jumlah token, dan kredit yang ditagih, beserta total
token/kredit. Satu baris ditulis **sekali per balasan yang berhasil ditagih**
(idempoten), bukan per permintaan, sehingga percobaan ulang tidak menghitung
ganda.

---

## 7. Pemecahan masalah

| Gejala | Kemungkinan sebab |
| --- | --- |
| SINA menjawab "tidak tersedia" | endpoint nonaktif, atau `enabled=false` pada konfigurasi mitra |
| Error `http 401/403` | API key salah/kosong |
| Error `http 404` | base URL salah — pastikan berakhiran `/api` (Open WebUI), bukan `/v1` |
| Error koneksi/timeout | Mac Mini tidak dijangkau dari server LBA; cek IP LAN, firewall, dan port 3000 |
| "Saldo token AI tidak mencukupi" | dompet SINA pasien habis; tambahkan kredit |
| Ditolak saat menyimpan endpoint | host publik tetapi memakai HTTP — gunakan HTTPS atau host LAN |
