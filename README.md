# Basreng POS — PHP Native + MySQL

Aplikasi MVP kasir/penjualan basreng dengan role seller dan buyer.

## Fitur
- Login, registrasi pembeli, halaman profil untuk mengubah nama pengguna/email/alamat/password, dan reset password melalui tautan email; role penjual/pembeli terpisah.
- Dashboard pendapatan, pembelian bahan baku, estimasi laba.
- CRUD sederhana produk (tambah/nonaktifkan), stok dan harga.
- Pencatatan bahan baku dan pembelian bahan baku.
- Katalog pembeli, checkout, riwayat pesanan.
- Ongkir otomatis berdasarkan alamat di akun pembeli: gratis untuk semua tujuan di Kecamatan Pasar Kemis, Sepatan, dan Rajeg, serta Kelurahan Jatiwaringin di Kecamatan Mauk; Rp25.000 untuk Jabodetabek; Rp50.000 untuk luar Jabodetabek.
- Upload QR/barcode pembayaran penjual untuk ditampilkan saat checkout.
- Status pembayaran dan status pesanan dikelola secara terpisah oleh penjual.

## Persyaratan
- PHP 8.0+ dengan PDO MySQL, fileinfo, dan OpenSSL.
- Composer 2 untuk memasang PHPMailer.
- MySQL 5.7+/8.0 atau MariaDB.
- Apache/Nginx (XAMPP/Laragon bisa digunakan).

## Struktur folder
- `pages/auth/` menyimpan halaman login, registrasi, profil, dan logout.
- `pages/buyer/` menyimpan halaman shop, checkout, pembayaran, dan pesanan pembeli.
- `pages/seller/` menyimpan halaman dashboard dan pengelolaan seller.
- `pages/api/` menyimpan endpoint data wilayah.
- `includes/` menyimpan konfigurasi bersama; `includes/partials/` menyimpan navigasi seller dan buyer.
- `assets/css/` menyimpan stylesheet.
- `database/` menyimpan skema dan migrasi.
- `uploads/` menyimpan gambar produk, bukti pembayaran, dan QR.
- `index.php` di root mengarahkan pengguna ke halaman sesuai perannya.

## Instalasi
1. Salin folder `basreng-pos` ke document root (misalnya `htdocs`).
2. Buat database dan import `database/schema.sql` melalui phpMyAdmin atau:
   `mysql -u root -p < database/schema.sql`
3. Sesuaikan DB_HOST, DB_NAME, DB_USER, DB_PASS pada `includes/config.php`.
4. Pastikan folder `uploads/` dapat ditulis oleh web server (aplikasi akan membuat folder saat QR diunggah).
5. Jalankan `composer install` dari folder proyek untuk memasang PHPMailer.
6. Konfigurasikan SMTP email di Apache (lihat langkah XAMPP berikut).
7. Buka `http://localhost/basreng-pos/pages/auth/login.php`.

### Konfigurasi SMTP Email di XAMPP

1. Siapkan kredensial SMTP Brevo dan alamat pengirim yang telah diverifikasi.
2. Hentikan Apache dari XAMPP Control Panel. Buka `C:\xampp\apache\conf\httpd.conf` dengan editor teks sebagai administrator.
3. Tambahkan di bagian paling bawah file, ganti contoh dengan akun dan App Password milik Anda:

   ```apache
   SetEnv GMAIL_SMTP_USERNAME "login-smtp"
   SetEnv GMAIL_SMTP_APP_PASSWORD "kunci-smtp"
   SetEnv GMAIL_SMTP_FROM_EMAIL "email-pengirim-terverifikasi@example.com"
   SetEnv GMAIL_SMTP_FROM_NAME "BasrengQyuu Store"
   ```

4. Simpan file, lalu jalankan kembali Apache dari XAMPP Control Panel. Variabel akan tersedia untuk PHP selama Apache berjalan.
5. Kirim permintaan reset password dan uji pembaruan status pesanan untuk memeriksa email. Pengaturan SMTP yang sama digunakan untuk email reset password dan notifikasi pesanan. Jika email gagal terkirim, periksa `C:\xampp\apache\logs\error.log`.

Jangan masukkan kredensial SMTP asli ke kode aplikasi atau README, dan jangan membagikannya.

Untuk database yang sudah berjalan, jalankan migrasi `database/migrations/003_order_shipping.sql`, `database/migrations/004_free_shipping_regions.sql`, `database/migrations/005_backfill_order_shipping.sql`, `database/migrations/006_split_payment_and_order_status.sql`, `database/migrations/007_password_reset_tokens.sql`, `database/migrations/008_add_order_tracking_number.sql`, `database/migrations/009_order_notifications.sql`, dan `database/migrations/010_order_refund_return_notifications.sql` masing-masing satu kali (lewati migrasi yang sudah diterapkan). Migrasi 005 juga menetapkan ongkir pesanan lama yang belum memiliki area. Migrasi 006 memisahkan status pembayaran dari status pesanan dan memetakan data status lama. Migrasi 007 menambahkan penyimpanan token reset password, migrasi 008 menambahkan nomor resi pengiriman, migrasi 009 menambahkan penyimpanan notifikasi pembeli, dan migrasi 010 menambahkan jenis notifikasi pengembalian dana dan pesanan dikembalikan. Email pembaruan dikirim saat pembayaran berubah menjadi lunas/dikembalikan atau status pesanan berubah menjadi proses pengiriman/selesai/dikembalikan, khusus pesanan QRIS dan transfer BCA. Mengisi nomor resi di detail pesanan otomatis mengubah status menjadi Proses Pengiriman. Ongkir pesanan baru dihitung saat checkout dari alamat wilayah pembeli di database; tidak perlu input jarak atau konfirmasi penjual sebelum pembayaran.

## Akun penjual awal
- Email: `admin@basreng.local`
- Password: `password`

Segera ganti password admin setelah login. Untuk deployment publik, gunakan HTTPS, password kuat, backup, validasi tambahan, dan pembatasan akses file upload.

## Alur pembayaran QR
Penjual mengunggah gambar QRIS/barcode miliknya pada menu **Pembayaran**. Pembeli checkout dan melihat QR tersebut. Setelah pembeli mengunggah bukti pembayaran, status pembayaran menjadi menunggu verifikasi seller; seller dapat memperbarui status pembayaran tanpa mengubah status pengiriman pesanan.

**Penting:** Ini adalah tampilan QR statis, bukan integrasi payment gateway otomatis. Nominal transaksi tidak otomatis tertanam ke QR dan aplikasi tidak dapat mendeteksi pembayaran masuk. Untuk QRIS dinamis/otomatis dibutuhkan akun merchant Midtrans/Xendit/provider lain, API key, endpoint create payment, webhook, dan verifikasi signature.

## Catatan perhitungan laba
Dashboard menampilkan total nilai seluruh pesanan, termasuk yang belum dibayar. Estimasi laba MVP tetap dihitung dari `pendapatan dibayar - total pembelian bahan baku`. Ini belum merupakan laba bersih akuntansi yang akurat karena belum ada resep/BOM untuk menghubungkan pemakaian bahan ke setiap produk, biaya kemasan, tenaga kerja, ongkir, dan biaya operasional. Untuk hasil yang akurat, tambahkan modul HPP/resep produksi dan biaya operasional.
