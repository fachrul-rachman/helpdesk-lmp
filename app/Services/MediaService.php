<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MediaService
{
    public const WHATSAPP_IMAGE_MAX_BYTES = 5_242_880;

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

    /**
     * @return array{media_type:string,key:string,mime_type:string,file_name:string,compressed:bool,fallback_reason:?string}
     */
    public function prepareImageForWhatsApp(string $key, string $fileName): array
    {
        $fileName = $this->safeFileName($fileName !== '' ? $fileName : basename(str_replace('\\', '/', $key)));
        $mimeType = $this->mimeTypeFromKey($key);
        $size = $this->storageObjectSizeOrFail($key);

        if ($size <= self::WHATSAPP_IMAGE_MAX_BYTES) {
            return [
                'media_type' => 'image',
                'key' => $key,
                'mime_type' => $mimeType,
                'file_name' => $fileName,
                'compressed' => false,
                'fallback_reason' => null,
            ];
        }

        $compressed = $this->compressImageForWhatsApp($key, $fileName);
        if ($compressed) {
            return $compressed;
        }

        Log::warning('media.image.fallback_document', [
            'key' => $key,
            'size_bytes' => $size,
        ]);

        return [
            'media_type' => 'document',
            'key' => $key,
            'mime_type' => $mimeType,
            'file_name' => $fileName,
            'compressed' => false,
            'fallback_reason' => 'image_too_large_compress_failed',
        ];
    }

    public function storageObjectSizeOrFail(string $key): int
    {
        try {
            return (int) Storage::disk('r2')->size($key);
        } catch (\Throwable $e) {
            Log::warning('media.storage.size_failed', ['key' => $key, 'error' => $e->getMessage()]);

            throw new HttpException(422, 'File media tidak ditemukan di storage.');
        }
    }

    public function downloadFromMeta(string $mediaId): array
    {
        $metaToken = (string) config('services.meta_whatsapp.token', '');
        $baseUrl = rtrim((string) config('services.meta_whatsapp.api_url', 'https://graph.facebook.com/v18.0'), '/');

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
        $base = rtrim((string) config('filesystems.disks.r2.url', ''), '/');

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

    /**
     * @return array{media_type:string,key:string,mime_type:string,file_name:string,compressed:bool,fallback_reason:?string}|null
     */
    private function compressImageForWhatsApp(string $key, string $fileName): ?array
    {
        if (! function_exists('imagecreatefromstring')) {
            Log::warning('media.image.compress_unavailable', ['key' => $key, 'driver' => 'gd']);

            return null;
        }

        try {
            $contents = Storage::disk('r2')->get($key);
        } catch (\Throwable $e) {
            Log::warning('media.image.read_failed', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }

        if (! is_string($contents) || $contents === '') {
            Log::warning('media.image.read_empty', ['key' => $key]);

            return null;
        }

        $source = @imagecreatefromstring($contents);
        if (! $source instanceof \GdImage) {
            Log::warning('media.image.decode_failed', ['key' => $key]);

            return null;
        }

        try {
            $compressed = $this->encodeCompressedJpeg($source);
        } finally {
            imagedestroy($source);
        }

        if (! $compressed) {
            Log::warning('media.image.compress_failed', ['key' => $key]);

            return null;
        }

        $year = now()->format('Y');
        $month = now()->format('m');
        $compressedKey = "media/{$year}/{$month}/compressed/".Str::uuid()->toString().'.jpg';
        Storage::disk('r2')->put($compressedKey, $compressed);

        Log::info('media.image.compressed', [
            'original_key' => $key,
            'compressed_key' => $compressedKey,
            'compressed_size_bytes' => strlen($compressed),
        ]);

        return [
            'media_type' => 'image',
            'key' => $compressedKey,
            'mime_type' => 'image/jpeg',
            'file_name' => $this->replaceExtension($fileName, 'jpg'),
            'compressed' => true,
            'fallback_reason' => null,
        ];
    }

    private function encodeCompressedJpeg(\GdImage $source): ?string
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $maxDimensions = [1600, 1280, 1024, 768];
        $qualities = [82, 74, 66, 58, 50];

        foreach ($maxDimensions as $maxDimension) {
            $ratio = min(1, $maxDimension / max($sourceWidth, $sourceHeight));
            $targetWidth = max(1, (int) floor($sourceWidth * $ratio));
            $targetHeight = max(1, (int) floor($sourceHeight * $ratio));

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            if (! $canvas instanceof \GdImage) {
                continue;
            }

            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

            foreach ($qualities as $quality) {
                ob_start();
                imagejpeg($canvas, null, $quality);
                $encoded = ob_get_clean();

                if (is_string($encoded) && $encoded !== '' && strlen($encoded) <= self::WHATSAPP_IMAGE_MAX_BYTES) {
                    imagedestroy($canvas);

                    return $encoded;
                }
            }

            imagedestroy($canvas);
        }

        return null;
    }

    private function replaceExtension(string $fileName, string $extension): string
    {
        $base = pathinfo($this->safeFileName($fileName), PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'file';
        }

        return "{$base}.{$extension}";
    }

    private function safeFileName(string $fileName): string
    {
        $fileName = basename(str_replace('\\', '/', trim($fileName)));

        return ($fileName !== '' && $fileName !== '.' && $fileName !== '..') ? $fileName : 'file';
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
