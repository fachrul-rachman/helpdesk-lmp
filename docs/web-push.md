# Web Push Helpdesk

Implementasi memakai Web Push/VAPID, tanpa akun Firebase. Notifikasi hanya berisi nomor ticket dan kalimat pemicu, bukan pesan/nama customer/judul/deskripsi ticket.

## Aktivasi production (belum dijalankan oleh implementasi)

1. Deploy kode dan jalankan `composer install --no-dev --optimize-autoloader` serta `npm ci && npm run build` pada proses deployment. PHP memerlukan curl, openssl, mbstring; gmp/bcmath disarankan untuk performa enkripsi.
2. Biarkan `WEBPUSH_ENABLED=false` saat migration belum terpasang. Migration baru hanya menambah tabel `push_subscriptions`; tidak mengubah data ticket/customer/user.
3. Jalankan migration tambahan ini sesuai prosedur backup/deployment production:

   ```sh
   php artisan migrate --path=database/migrations/2026_09_03_000000_create_push_subscriptions_table.php --force
   ```

   Jangan menggunakan `migrate:fresh`, `migrate:refresh`, atau seeder.

4. Buat satu pasangan VAPID pada lingkungan tepercaya, lalu simpan di secret manager/environment server:

   ```sh
   php -r "require 'vendor/autoload.php'; echo json_encode(Minishlink\WebPush\VAPID::createVapidKeys());"
   ```

   Isi `WEBPUSH_VAPID_PUBLIC_KEY`, `WEBPUSH_VAPID_PRIVATE_KEY`, dan `WEBPUSH_VAPID_SUBJECT=mailto:alamat-email-operasional`. Jangan commit private key, jangan menambahkan private key ke variabel `VITE_*`, dan jangan mengganti pasangan key setiap deploy. Jika key dirotasi, pengguna harus mengaktifkan ulang notifikasi.
5. Set `WEBPUSH_ENABLED=true`. `WEBPUSH_QUEUE_CONNECTION` default `database`; dapat memakai koneksi async database/redis/sqs/beanstalkd yang telah dikonfigurasi. Worker harus mendengarkan default queue milik koneksi tersebut. Sync/deferred sengaja tidak didukung agar pengiriman push tidak membebani request chat.
6. Terapkan config terbaru dan restart worker melalui prosedur deployment yang biasa. Scheduler SLA existing harus tetap berjalan.
7. Akses lewat HTTPS. Pastikan `/helpdesk-push-sw.js` dilayani sebagai JavaScript, tidak dialihkan ke login, tidak di-cache permanen oleh CDN. Manifest berada di `/build/manifest.webmanifest`; scope worker notifikasi adalah `/app/`. Worker push tidak menyimpan halaman/API terautentikasi.
8. PIC/SPV menekan **Aktifkan notifikasi** pada setiap perangkat dan memberikan izin. iPhone/iPad memerlukan iOS/iPadOS 16.4+ dan aplikasi dibuka dari Layar Utama. Android/desktop memerlukan browser yang mendukung Web Push. Sistem operasi/browser tetap dapat menunda notifikasi karena mode hemat daya atau pengaturan pengguna.

## Penerima dan pemicu

- Pesan **customer** pada ticket aktif: penanggung jawab saat ini.
- Assignment: PIC yang ditugaskan (assignment SPV tidak memicu notifikasi assignment).
- Transisi tepat `on_progress -> open`: penanggung jawab saat ini.
- SLA respons pertama/penyelesaian, reminder maupun overdue: penanggung jawab saat ini; jadwal mengikuti konfigurasi SLA existing.
- Takeover approved: SPV `approved_by`, selama requester masih assignee ticket. Selain itu memakai `assigned_to`. Ticket tanpa penanggung jawab tidak disiarkan ke semua SPV.
- Job memeriksa ulang penerima, status ticket, deadline SLA, sesi login, role dan status aktif akun sebelum mengirim. Job kedaluwarsa setelah 5 menit; pesan customer tidak masuk payload job. Endpoint mati (404/410) dihapus; kegagalan sementara dicoba ulang maksimal 3 kali.
- Satu langganan browser hanya terhubung ke satu akun. Logout/unsubscribe membersihkan binding lokal; account switch memerlukan aktivasi ulang. Refresh token dicabut/kedaluwarsa juga menghentikan pengiriman. Perangkat lain tetap independen.

## API

Semua endpoint memerlukan JWT, role PIC/SPV, dan rate limit 30 request/menit.

- `GET /api/push/config`: `enabled`, `public_key`; aman sebelum migration selama flag disabled.
- `POST /api/push/subscriptions`: objek `PushSubscription.toJSON()` (`endpoint`, `keys.p256dh`, `keys.auth`) ditambah `refresh_token` sesi perangkat yang sama. Endpoint dibatasi ke provider push Chrome/Firefox/Apple/Windows, disimpan terenkripsi, dan tidak boleh diambil alih akun lain.
- `DELETE /api/push/subscriptions`: `{ "endpoint": "..." }`, hanya langganan milik akun terautentikasi.

## Pemeriksaan tanpa database

```sh
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/TicketPushNotificationTest.php
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/SendTicketPushJobTest.php
node --test tests/push-worker.test.mjs
npx tsc --noEmit
```

Uji nyata setelah deployment: aktifkan dua perangkat pada PIC A, satu SPV, dan PIC B. Pastikan PIC B tidak menerima ticket A; uji assignment, pesan customer, pesan AI/PIC (tidak memicu), reopen, SLA, takeover, reassign sebelum queue diproses, logout/account switch, revoke permission, dan klik notifikasi saat sesi expired. Pengiriman nyata belum diverifikasi tanpa VAPID, migration, worker dan izin perangkat.

Audit Composer saat implementasi melaporkan 42 advisory pada 14 paket existing (termasuk severity critical/high); dependency baru Web Push tidak tercantum. Pembaruan seluruh dependency perlu pekerjaan terpisah, bukan dianggap sudah bersih oleh fitur ini.
