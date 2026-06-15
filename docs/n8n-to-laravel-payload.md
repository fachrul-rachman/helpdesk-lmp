# Kontrak Payload n8n ke Laravel

Endpoint:

```http
POST /api/webhook/n8n
X-N8N-Secret: {N8N_INCOMING_SECRET}
Content-Type: application/json
```

Catatan penting:
- Body request harus berupa object JSON, bukan array JSON.
- Semua request wajib punya `event`.
- Nomor customer boleh format lokal seperti `08123456789`; Laravel akan normalisasi ke `628123456789`.
- Untuk balasan teks, Laravel menerima beberapa format lama, tapi dokumentasi ini memilih satu format yang paling rapi.

## 1. Balasan Teks

Gunakan ini kalau n8n hanya mengirim teks ke customer.

```json
{
  "event": "message.reply",
  "customer_phone_number": "08123456789",
  "ai_reply": {
    "type": "text",
    "message": "Jam operasional kami Senin-Jumat 08.00-17.00."
  }
}
```

Laravel akan:
- menyimpan pesan sebagai pesan dari AI;
- mengirim teks ke WhatsApp customer;
- menampilkan pesan di percakapan tiket jika customer sedang punya tiket aktif.

## 2. Balasan Media

Gunakan ini kalau n8n mengirim file dari storage.

```json
{
  "event": "message.reply",
  "customer_phone_number": "08123456789",
  "ai_reply": {
    "type": "media",
    "media_type": "document",
    "key": "media/2026/05/Update Zone B Tangerang 15 Mei 2026.pdf",
    "caption": "Berikut file update Zone B Tangerang 15 Mei 2026."
  }
}
```

Field media:

| Field | Wajib | Keterangan |
| --- | --- | --- |
| `ai_reply.type` | Ya | Isi `media`. |
| `ai_reply.media_type` | Ya | `image`, `video`, atau `document`. |
| `ai_reply.key` | Ya | Object key file di storage, bukan URL penuh. |
| `ai_reply.caption` | Tidak | Caption media, maksimal 1024 karakter. |
| `ai_reply.filename` | Tidak | Nama file untuk dokumen. Jika kosong, Laravel ambil dari `key`. |

`media_type` harus sesuai dengan ekstensi file:
- `.jpg`, `.jpeg`, `.png`, `.webp` -> `image`
- `.mp4`, `.3gp` -> `video`
- `.pdf`, `.doc`, `.docx`, `.xls`, `.xlsx` -> `document`

Contoh yang salah:

```json
{
  "event": "message.reply",
  "customer_phone_number": "08123456789",
  "ai_reply": {
    "type": "media",
    "media_type": "image",
    "key": "media/2026/05/Update Zone B Tangerang 15 Mei 2026.pdf",
    "caption": "Berikut filenya."
  }
}
```

Itu salah karena file `.pdf` harus `media_type: "document"`, bukan `image`.

## 3. Kirim Gambar

```json
{
  "event": "message.reply",
  "customer_phone_number": "08123456789",
  "ai_reply": {
    "type": "media",
    "media_type": "image",
    "key": "media/2026/05/panduan-reset-router.jpg",
    "caption": "Berikut panduan reset router."
  }
}
```

## 4. Kirim PDF atau Dokumen

```json
{
  "event": "message.reply",
  "customer_phone_number": "08123456789",
  "ai_reply": {
    "type": "media",
    "media_type": "document",
    "key": "media/2026/05/Update Zone B Tangerang 15 Mei 2026.pdf",
    "caption": "Berikut dokumennya."
  }
}
```

## 5. Kirim Video

```json
{
  "event": "message.reply",
  "customer_phone_number": "08123456789",
  "ai_reply": {
    "type": "media",
    "media_type": "video",
    "key": "media/2026/05/tutorial.mp4",
    "caption": "Berikut video tutorialnya."
  }
}
```

## 6. Membuat Tiket

```json
{
  "event": "ticket.create",
  "customer_phone_number": "08123456789",
  "ticket": {
    "subject": "Laptop tidak bisa menyala",
    "description": "Customer melaporkan laptop mati total setelah listrik padam.",
    "priority": "high",
    "division_id": "uuid-division",
    "ai_confidence": 0.92,
    "is_fallback": false
  },
  "ai_reply": {
    "type": "text",
    "message": "Tiket Anda sudah kami buat dan akan segera ditindaklanjuti."
  }
}
```

## 7. Buka Ulang Tiket dari On Progress

```json
{
  "event": "ticket.reopen_from_on_progress",
  "ticket_id": "uuid-ticket",
  "ai_reply": {
    "type": "text",
    "message": "Baik, kami hubungkan kembali ke tim terkait."
  }
}
```

## 8. Error dari n8n

```json
{
  "event": "system.error",
  "customer_phone_number": "08123456789",
  "error": "AI workflow timeout"
}
```

Laravel akan mengirim template WhatsApp `system_error` ke customer. Field `error` hanya untuk informasi internal, bukan isi pesan ke customer.
