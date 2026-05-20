<?php

namespace App\Services;

use App\Models\MetaWhatsappTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MetaWhatsAppTemplateService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllTemplates(): array
    {
        $token = (string) (getenv('META_WA_TOKEN') ?: env('META_WA_TOKEN', ''));
        $wabaId = (string) (getenv('META_WA_WABA_ID') ?: env('META_WA_WABA_ID', ''));
        $apiUrl = rtrim((string) (getenv('META_WA_API_URL') ?: env('META_WA_API_URL', 'https://graph.facebook.com/v18.0')), '/');

        if ($token === '' || $wabaId === '' || $apiUrl === '') {
            throw new HttpException(422, 'Konfigurasi Meta WA belum lengkap (META_WA_TOKEN, META_WA_WABA_ID, META_WA_API_URL).');
        }

        $templates = [];
        $after = null;

        do {
            $params = [
                'fields' => 'id,name,status,category,language,components',
                'limit' => 100,
            ];
            if (is_string($after) && $after !== '') {
                $params['after'] = $after;
            }

            $url = "{$apiUrl}/{$wabaId}/message_templates";
            $resp = Http::timeout(20)->withToken($token)->get($url, $params);

            if (!$resp->successful()) {
                $status = $resp->status();
                $body = $resp->json();
                throw new HttpException($status >= 400 && $status < 600 ? $status : 500, 'Gagal mengambil template dari Meta.');
            }

            $json = $resp->json();
            $data = $json['data'] ?? [];
            if (is_array($data)) {
                foreach ($data as $item) {
                    if (is_array($item)) {
                        $templates[] = $item;
                    }
                }
            }

            $after = $json['paging']['cursors']['after'] ?? null;
        } while (is_string($after) && $after !== '');

        return $templates;
    }

    /**
     * @return array{synced:int,total:int}
     */
    public function syncToDatabase(): array
    {
        $items = $this->fetchAllTemplates();
        $now = CarbonImmutable::now();

        $rows = [];
        foreach ($items as $t) {
            $metaId = (string) ($t['id'] ?? '');
            $name = (string) ($t['name'] ?? '');
            if ($metaId === '' || $name === '') {
                continue;
            }

            $language = $t['language'] ?? null;
            $languageCode = null;
            if (is_array($language)) {
                $languageCode = (string) ($language['code'] ?? '');
            } elseif (is_string($language)) {
                $languageCode = $language;
            }

            $rows[] = [
                'meta_template_id' => $metaId,
                'name' => $name,
                'language' => $languageCode !== '' ? $languageCode : null,
                'status' => isset($t['status']) ? (string) $t['status'] : null,
                'category' => isset($t['category']) ? (string) $t['category'] : null,
                'sub_category' => isset($t['sub_category']) ? (string) $t['sub_category'] : null,
                'components' => isset($t['components']) && is_array($t['components']) ? json_encode($t['components']) : null,
                'raw' => json_encode($t),
                'last_synced_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }

        if ($rows === []) {
            return ['synced' => 0, 'total' => 0];
        }

        DB::transaction(function () use ($rows): void {
            DB::table((new MetaWhatsappTemplate())->getTable())->upsert(
                $rows,
                ['meta_template_id'],
                ['name', 'language', 'status', 'category', 'sub_category', 'components', 'raw', 'last_synced_at', 'updated_at'],
            );
        });

        return ['synced' => count($rows), 'total' => count($items)];
    }
}

