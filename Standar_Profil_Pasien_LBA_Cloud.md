# Standar Profil Pasien & Pemeriksaan LBA Cloud

Rancangan formulir klinik rawat jalan untuk ditinjau penanggung jawab medis dan dipakai sebagai spesifikasi TRAE. Acuan awal adalah praktik dan interoperabilitas rekam medis di Indonesia. Rancangan ini memakai data dummy untuk uji implementasi.

## 1. Profil pasien yang terbawa ke kunjungan berikutnya

| Bagian | Isian | Muncul sebagai |
|---|---|---|
| Identitas | Nomor rekam medis per Mitra, nama lengkap, tanggal lahir, jenis kelamin sesuai data registrasi, kontak dan alamat sesuai kebutuhan registrasi. Tambahkan identitas resmi, penjamin, atau identitas SATUSEHAT saat diperlukan oleh proses klinik. | Identitas pasien. Umur dihitung dari tanggal lahir. |
| Wali dan kontak | Nama dan kontak wali atau pendamping serta hubungan dengan pasien bila relevan. Kontak darurat sesuai kebutuhan klinik. | Bagian registrasi dengan akses terbatas. |
| Alergi dan intoleransi | Zat/obat/makanan/bahan, reaksi, tingkat keparahan, waktu kejadian jika diketahui, sumber informasi, dan verifikasi tenaga kesehatan. Catat alergi dan intoleransi sesuai penilaian klinis. | Peringatan di atas profil dan pada kunjungan. Pilihan status: **ada**, **tidak ada yang diketahui**, **belum diketahui**. |
| Masalah kesehatan | Nama kondisi, sejak kapan, status aktif/teratasi/tidak diketahui, sumber, serta tenaga kesehatan yang mengonfirmasi. Contoh: hipertensi, diabetes, asma, penyakit jantung, ginjal atau hati, dan gangguan perdarahan bila relevan. | Daftar masalah aktif dan riwayat, dengan penanda **dilaporkan pasien** atau **terverifikasi**. |
| Obat dan suplemen | Nama, kekuatan sediaan bila diketahui, dosis per pemakaian, cara pakai, frekuensi, indikasi jika diketahui, tanggal mulai/berhenti, status penggunaan, dan sumber. Cakup resep, obat bebas, herbal, dan suplemen. | Daftar konsumsi yang bisa ditinjau ulang saat kunjungan. |
| Riwayat terdahulu | Operasi, rawat inap, tindakan, dan komplikasi penting. Riwayat keluarga yang relevan meliputi kondisi dan hubungan keluarga tanpa mengumpulkan identitas yang tidak diperlukan. | Riwayat klinis. |
| Konteks tambahan | Merokok, alkohol, aktivitas, pola makan, tidur, dan paparan kerja jika relevan. Status kehamilan/menyusui ditanya jika relevan bagi penilaian atau tindakan. | Form kondisional, ditinjau ulang saat diperlukan. |

Bila pasien mengisi mandiri, tampilkan sebagai laporan pasien sampai petugas memverifikasi. Simpan waktu terakhir tinjau dan siapa yang meninjau untuk alergi, obat, dan masalah aktif.

## 2. Form setiap kunjungan

| Bagian | Isian |
|---|---|
| Identitas kunjungan | ID kunjungan, nomor rekam medis pasien, cabang, tanggal/jam dan zona waktu tampilan, jenis kunjungan (baru/kontrol), petugas, penanggung jawab klinis, serta alasan datang. |
| Anamnesis | Keluhan utama dan penyerta, mulai kapan, perkembangan, lokasi dan intensitas bila relevan, pemicu/pereda, dampak pada aktivitas, tindakan atau obat yang sudah dicoba, serta sumber informasi seperti pasien atau wali. |
| Tinjau riwayat | Konfirmasi perubahan kondisi aktif, obat, dan alergi sejak kunjungan sebelumnya. Tampilkan ringkasan terakhir dengan tanggalnya. |
| Pemeriksaan fisik | Kondisi umum dan temuan pemeriksaan terarah sesuai keluhan. Catat bagian tubuh yang diperiksa dan temuannya. |
| Tanda vital dan antropometri | Tekanan darah sistolik/diastolik dalam mmHg, nadi per menit, napas per menit, suhu °C, SpO₂ dalam %, berat kg, dan tinggi cm sesuai indikasi atau SOP. Hitung IMT jika relevan dan informasinya tersedia. Untuk tensi simpan posisi, sisi lengan, pengukuran pertama dan ulangan, serta keterangan alat atau ukuran manset bila dicatat. |
| Gula darah | Jika diperiksa, isi **nilai, satuan mg/dL atau mmol/L, jenis pemeriksaan, jenis sampel, metode, jam pengukuran, dan waktu makan terakhir jika relevan**. Bedakan puasa, sewaktu, dan setelah makan. Bedakan hasil kapiler dari glukometer dengan glukosa plasma dari laboratorium. Simpan HbA1c sebagai jenis pemeriksaan tersendiri dalam %. |
| Hasil penunjang | Ringkasan hasil yang relevan, misalnya darah lengkap, HbA1c, atau profil lipid. Catat nama pemeriksaan, nilai, satuan, rentang rujukan dari lab bila tersedia, tanggal pengambilan sampel/hasil, sumber, dan dokumen pendukung. |
| Penilaian dan rencana | Catatan dalam format SOAP bila sesuai alur klinik. Pisahkan penilaian klinis atau diagnosis dari temuan pemeriksaan. Tenaga medis yang berwenang mengisi assessment, pemeriksaan lanjutan, edukasi, rujukan, jadwal kontrol, serta autentikasi dan waktu finalisasi. |

### Format data pengukuran

Setiap pengukuran mempunyai jenis pemeriksaan, nilai terstruktur, satuan, waktu, petugas atau sumber, metode, konteks pengukuran, dan tautan ke kunjungan. Status data dapat berupa **terukur**, **belum diperiksa**, **tidak tersedia**, atau **tidak relevan**. Nilai kosong berarti belum ada nilai. Nilai nol hanya dipakai jika hasil asli memang nol.

Contoh dummy: **Tekanan darah 132/84 mmHg**, duduk, lengan kiri, pukul 09.05 WIB. Pengukuran ulang **128/82 mmHg**, pukul 09.07 WIB. Simpan dua pembacaan dalam kunjungan yang sama. Profil boleh menampilkan pembacaan terbaru dan tanggalnya.

Contoh dummy: **Glukosa kapiler sewaktu 118 mg/dL**, glukometer di klinik, pukul 09.10 WIB, makan terakhir pukul 07.00 WIB. Contoh ini menunjukkan format pencatatan.

## 3. Catatan pemeriksaan LBA

Kaitkan pemeriksaan LBA ke kunjungan dan pasien yang sama. Gunakan modul LBA yang sudah ada.

| Kelompok | Isian |
|---|---|
| Tujuan dan persetujuan | Tujuan observasi, persetujuan tindakan dan citra sesuai SOP, serta persetujuan pemrosesan AI jika relevan. Simpan versi persetujuan, waktu, dan petugas. |
| Identitas pemeriksaan | Pemeriksa, lokasi, waktu pengambilan sampel dan perekaman, jenis sampel sesuai SOP, ID spesimen/slide, alat, mode mikroskop, pembesaran, dan mode kerja Manual/AI/Hybrid jika tersedia. |
| Citra dan mutu | Citra asli di penyimpanan privat, tautan ke pemeriksaan, waktu, sumber citra, kualitas, artefak atau keterbatasan, dan alasan jika citra tidak layak dinilai. |
| Observasi dan review | Deskripsi visual, anotasi dengan rujukan citra, asal anotasi manusia atau AI, catatan pemeriksa, reviewer, waktu persetujuan, serta versi dan waktu rilis laporan. |

Bukti yang tersedia tidak mendukung Live Blood Analysis sebagai metode diagnosis yang andal. Catat LBA sebagai observasi terpisah. Penilaian klinis mengacu pada anamnesis, pemeriksaan fisik, dan pemeriksaan yang tervalidasi sesuai indikasi. Observasi LBA dan anotasi AI tidak membuat diagnosis, kode penyakit, atau rekomendasi obat/suplemen secara otomatis.

## 4. Tampilan dan hak akses

- Header tenaga medis menampilkan nama, nomor rekam medis, umur, alergi penting, masalah aktif, obat aktif, dan hasil terbaru bertanggal. Gunakan tab **Ringkasan**, **Riwayat & Obat**, **Kunjungan**, **Hasil & Tren**, serta **Pemeriksaan LBA**.
- Saat kunjungan baru, tampilkan riwayat untuk ditinjau. Nilai tensi dan gula kunjungan lama tetap ada di linimasa. Pengukuran baru ditambahkan sebagai catatan baru. Grafik gula darah membedakan jenis dan metode pemeriksaan, dengan satuan yang konsisten.
- Gunakan hak akses Klinik dan batas tenant/cabang yang sudah ada. Resepsionis mengelola identitas administratif sesuai perannya. Tenaga kesehatan yang berwenang mengisi catatan klinis. Reviewer berwenang merilis laporan. Pasien melihat data miliknya yang diizinkan untuk ditampilkan. Principal hanya melihat data operasional agregat.
- Lampiran, citra, dan laporan disimpan privat. Koreksi catatan yang sudah final menggunakan amandemen dengan alasan, waktu dan identitas petugas, dengan versi sebelumnya dapat diaudit sesuai kebijakan klinik. Catat aktivitas penting seperti perubahan dan rilis laporan.

## 5. Pemetaan dan kriteria penerimaan TRAE

Verifikasi dulu field, model, visit flow, kebijakan akses, dan form LBA yang sudah ada. Pakai ulang identitas pasien, consent, serta rilis laporan yang aktif. Petakan konsep ke SATUSEHAT untuk interoperabilitas, misalnya `Patient`, `Encounter`, `Observation`, `AllergyIntolerance`, `Condition`, `MedicationStatement`, dan `DiagnosticReport`. Pelajari persyaratan resmi yang berlaku untuk jenis fasilitas sebelum mengklaim kepatuhan atau mengaktifkan integrasi.

- Dua kunjungan pasien menyimpan nilai pengukuran terpisah. Nilai lama tetap terlihat di riwayat.
- Petugas dapat membedakan data yang dilaporkan pasien dari data yang telah diverifikasi. Status alergi belum diketahui tampil berbeda dari tidak ada alergi yang diketahui.
- Pemeriksaan tanpa tes gula tetap dapat disimpan dengan status yang sesuai.
- Permintaan membaca citra, data pasien, atau laporan lintas tenant/pasien ditolak. Portal pasien hanya menyajikan laporan yang sudah disetujui dan dirilis sesuai izin.
- Catatan final memiliki jejak koreksi. Anotasi LBA/AI tidak mengisi diagnosis otomatis. Rilis laporan mengikuti review tenaga kesehatan berwenang yang sudah ada.
- Minta penanggung jawab medis klinik memvalidasi kolom wajib, SOP pengukuran, dan kapan petugas perlu mencatat tindak lanjut sebelum dipakai dengan pasien nyata.

## Acuan

- [SATUSEHAT Platform — Resume Medis Rawat Jalan](https://satusehat.kemkes.go.id/platform/docs/id/interoperability/rme-rawat-jalan/)
- [Kementerian Kesehatan RI — Permenkes 24/2022 tentang Rekam Medis](https://jdih.kemkes.go.id/documents/peraturan-menteri-kesehatan-nomor-24-tahun-2022)
- [Kementerian Kesehatan RI — KMK HK.01.07/MENKES/1423/2022 tentang Variabel dan Metadata RME](https://jdih.kemkes.go.id/documents/keputusan-menteri-kesehatan-nomor-hk0107menkes14232022)
- [American Heart Association — Blood Pressure Measurement](https://www.heart.org/en/health-topics/high-blood-pressure/understanding-blood-pressure-readings/monitoring-your-blood-pressure-at-home)
- [NIDDK — Diabetes & Prediabetes Tests](https://www.niddk.nih.gov/health-information/professionals/clinical-tools-patient-management/diabetes/diabetes-prediabetes)
- [Lab Tests Online UK — Evidence on Live Blood Cell Analysis](https://labtestsonline.org.uk/tests/unvalidated-or-misleading-laboratory-tests)
