<?php

namespace App\Jobs;

use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchPublicHolidaysJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $url = (string) config('services.holiday_api.url', '');
        $key = (string) config('services.holiday_api.key', '');

        if ($url === '' || $key === '') {
            Log::warning('holiday_api.missing_config');
            return;
        }

        $year = (int) CarbonImmutable::now()->format('Y');

        try {
            $resp = Http::timeout(20)
                ->withHeader('X-API-co-id', $key)
                ->get($url, ['year' => $year]);

            if (!$resp->successful()) {
                Log::warning('holiday_api.failed', ['status' => $resp->status()]);
                return;
            }

            $json = $resp->json();
            $items = is_array($json['data'] ?? null) ? $json['data'] : [];

            $rows = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if (($item['is_holiday'] ?? false) !== true) {
                    continue;
                }

                $date = (string) ($item['date'] ?? '');
                $name = (string) ($item['name'] ?? '');
                if ($date === '' || $name === '') {
                    continue;
                }

                $rows[] = [
                    'date' => $date,
                    'name' => $name,
                    'year' => $year,
                ];
            }

            if (count($rows) === 0) {
                Log::info('holiday_api.empty', ['year' => $year]);
                return;
            }

            PublicHoliday::query()->upsert($rows, ['date'], ['name', 'year']);

            Log::info('holiday_api.synced', ['year' => $year, 'count' => count($rows)]);
        } catch (\Throwable $e) {
            Log::warning('holiday_api.exception', ['error' => $e->getMessage()]);
        }
    }
}
