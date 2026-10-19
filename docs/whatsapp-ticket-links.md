# Link ticket pada notifikasi WhatsApp tim

Tombol URL bersifat opt-in per template. Default kosong, sehingga payload notifikasi lama tetap sama. Tidak ada endpoint atau migrasi baru.

## Pendaftaran di Meta

Pada template yang dipilih, tambahkan tombol pertama (index `0`):

- Jenis: **Visit website**.
- Teks tombol: **Buka Ticket**.
- Tipe URL: **Dynamic**.
- URL: `https://DOMAIN-APLIKASI/app/{{1}}`.
- Contoh URL: `https://DOMAIN-APLIKASI/app/pic/tickets/ID-TICKET-CONTOH` menggunakan ID ticket yang valid pada aplikasi Anda.

Gunakan domain masing-masing deployment. Pertahankan isi, nama variable body, dan bahasa template (`id`) yang sudah digunakan.

Variable URL tombol terpisah dari variable body. Aplikasi mengirim akhiran `pic/tickets/{id}` atau `spv/tickets/{id}` sesuai **penerima**, termasuk salinan penugasan kepada SPV. ID tersebut adalah ID internal ticket, bukan nomor tampil seperti `T26-00125`.

## Aktivasi bertahap

Hanya setelah tombol template disetujui Meta, tambahkan nama template ke `.env` VPS. Contoh mengaktifkan penugasan saja:

```dotenv
META_WA_TICKET_URL_TEMPLATES=ticket_assigned_to_agent
```

Nama template yang didukung:

| Notifikasi | Nama template |
| --- | --- |
| Penugasan/ticket baru, termasuk salinan ke SPV | `ticket_assigned_to_agent` |
| Ticket terbuka kembali | `ticket_on_progress_opened` |
| Pengingat SLA balasan pertama | `sla_first_response_warning` |
| SLA balasan pertama terlewati | `sla_first_response_breached` |
| Pengingat SLA penyelesaian | `sla_resolution_warning` |
| SLA penyelesaian terlewati | `sla_resolution_breached` |

Untuk beberapa template, gunakan daftar dipisah koma:

```dotenv
META_WA_TICKET_URL_TEMPLATES=ticket_assigned_to_agent,ticket_on_progress_opened
```

Jika belum ada tombol yang disetujui, biarkan kosong atau jangan tambahkan variabel ini:

```dotenv
META_WA_TICKET_URL_TEMPLATES=
```

Setelah perubahan kode/config di VPS:

```bash
php artisan config:cache
sudo systemctl restart helpdesk-amg-queue.service
```

Sesuaikan nama service jika deployment berbeda. Tidak perlu menjalankan migration atau mengubah database.

## Payload Meta

Body tetap mengikuti template lama. Untuk template yang diaktifkan, aplikasi menambahkan komponen berikut:

```json
{
  "type": "button",
  "sub_type": "url",
  "index": "0",
  "parameters": [
    { "type": "text", "text": "pic/tickets/ID-TICKET" }
  ]
}
```

Untuk template di luar daftar aktivasi, komponen tersebut tidak dikirim. Jangan aktifkan template sebelum tombol URL dinamisnya disetujui dan cocok dengan format di atas karena Meta dapat menolak payload yang tidak sesuai.

Link menggunakan halaman aplikasi yang sudah ada. Pengguna yang belum login diarahkan ke login lalu kembali ke ticket. Login dan hak akses ticket tetap berlaku, tanpa token/password pada URL. Penerusan link tidak memberi akses tambahan.

Notifikasi untuk customer dan tombol survei kepuasan tetap memakai perilaku sebelumnya.
