<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MediaService
{
    /**
     * @return array<int, string>
     */
    public function allowedMimeTypes(): array
    {
        return [
            'image/jpeg',
            'image/png',
            'image/webp',
            'video/mp4',
            'video/3gpp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    public function classifyTypeFromMime(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        return 'document';
    }

    public function classifyTypeFromKey(string $key): string
    {
        $mimeType = $this->guessMimeFromExtension(pathinfo($key, PATHINFO_EXTENSION));

        return $mimeType ? $this->classifyTypeFromMime($mimeType) : 'document';
    }

    public function mimeTypeFromKey(string $key): string
    {
        return $this->guessMimeFromExtension(pathinfo($key, PATHINFO_EXTENSION)) ?? 'application/octet-stream';
    }

    public function downloadFromMeta(string $mediaId): array
    {
        $metaToken = (string) (getenv('META_WA_TOKEN') ?: env('META_WA_TOKEN', ''));
        $baseUrl = rtrim((string) (getenv('META_WA_API_URL') ?: env('META_WA_API_URL', 'https://graph.facebook.com/v18.0')), '/');

        if ($metaToken === '') {
            throw new HttpException(500, 'Terjadi kesalahan pada server.');
        }

        $http = $this->metaHttp($metaToken);

        $metaResp = $http->get("{$baseUrl}/{$mediaId}");
        if (! $metaResp->successful()) {
            throw new HttpException(500, 'Terjadi kesalahan pada server.');
        }

        $meta = $metaResp->json();
        $url = (string) ($meta['url'] ?? '');
        $mimeType = (string) ($meta['mime_type'] ?? '');
        $sizeBytes = (int) ($meta['file_size'] ?? 0);

        if ($url === '') {
            throw new HttpException(500, 'Terjadi kesalahan pada server.');
        }

        if ($mimeType !== '' && ! in_array($mimeType, $this->allowedMimeTypes(), true)) {
            throw new HttpException(422, 'Tipe file tidak didukung.');
        }

        $bin = $http->get($url);
        if (! $bin->successful()) {
            throw new HttpException(500, 'Terjadi kesalahan pada server.');
        }

        $ext = $this->guessExtensionFromMime($mimeType) ?? 'bin';
        $tmpPath = storage_path('app/tmp/meta/'.Str::uuid()->toString().'.'.$ext);
        @mkdir(dirname($tmpPath), 0777, true);
        file_put_contents($tmpPath, $bin->body());

        return [
            'path' => $tmpPath,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes > 0 ? $sizeBytes : filesize($tmpPath),
            'ext' => $ext,
        ];
    }

    public function uploadToR2(string $localPath, string $prefix = 'media', ?string $extension = null): string
    {
        $year = now()->format('Y');
        $month = now()->format('m');
        $ext = $extension ?: pathinfo($localPath, PATHINFO_EXTENSION) ?: 'bin';
        $key = "{$prefix}/{$year}/{$month}/".Str::uuid()->toString().".{$ext}";

        $disk = Storage::disk('r2');

        // Jangan kirim instance File/UploadedFile ke Storage::put karena beberapa driver akan
        // menganggap $key sebagai "directory" dan membuat random filename di bawahnya.
        // Kita butuh object key persis sesuai $key.
        $stream = fopen($localPath, 'r');
        if ($stream === false) {
            throw new HttpException(422, 'File tidak valid.');
        }
        $disk->put($key, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $key;
    }

    public function uploadUploadedFileToR2(UploadedFile $file, string $prefix = 'media'): string
    {
        $mimeType = (string) ($file->getMimeType() ?? '');
        if ($mimeType === '' || ! in_array($mimeType, $this->allowedMimeTypes(), true)) {
            throw new HttpException(422, 'Tipe file tidak didukung.');
        }

        $ext = $file->getClientOriginalExtension();
        if ($ext === '') {
            $ext = $this->guessExtensionFromMime($mimeType) ?? 'bin';
        }

        $year = now()->format('Y');
        $month = now()->format('m');
        $key = "{$prefix}/{$year}/{$month}/".Str::uuid()->toString().".{$ext}";

        $realPath = $file->getRealPath();
        if (! $realPath) {
            throw new HttpException(422, 'File upload tidak valid.');
        }

        Storage::disk('r2')->put($key, file_get_contents($realPath));

        return $key;
    }

    public function getPublicUrl(string $r2Key): string
    {
        $base = rtrim((string) (getenv('CLOUDFLARE_R2_URL') ?: env('CLOUDFLARE_R2_URL', '')), '/');

        if ($base !== '') {
            $encodedKey = implode('/', array_map('rawurlencode', explode('/', ltrim($r2Key, '/'))));

            return $base.'/'.$encodedKey;
        }

        $disk = Storage::disk('r2');

        if (method_exists($disk, 'temporaryUrl')) {
            try {
                return $disk->temporaryUrl($r2Key, now()->addMinutes(15));
            } catch (\Throwable) {
                // fallback
            }
        }

        return $disk->url($r2Key);
    }

    public function delete(string $r2Key): void
    {
        Storage::disk('r2')->delete($r2Key);
    }

    private function metaHttp(string $token): PendingRequest
    {
        return Http::timeout(10)->withToken($token);
    }

    private function guessExtensionFromMime(string $mimeType): ?string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => null,
        };
    }

    private function guessMimeFromExtension(string $extension): ?string
    {
        return match (strtolower(ltrim($extension, '.'))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            '3gp', '3gpp' => 'video/3gpp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => null,
        };
    }
}
