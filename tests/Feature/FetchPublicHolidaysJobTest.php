<?php

use App\Jobs\FetchPublicHolidaysJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('holiday API request uses the provider co-id header', function () {
    config([
        'services.holiday_api.url' => 'https://holidays.test/api',
        'services.holiday_api.key' => 'test-co-id',
    ]);

    Http::fake([
        'https://holidays.test/api*' => Http::response([
            'data' => [
                [
                    'date' => '2026-01-01',
                    'name' => 'Tahun Baru Masehi',
                    'is_holiday' => true,
                ],
            ],
        ]),
    ]);

    (new FetchPublicHolidaysJob)->handle();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-API-co-id', 'test-co-id')
        && ! $request->hasHeader('X-API-Key')
        && $request['year'] === 2026
    );

    $this->assertDatabaseHas('public_holidays', [
        'date' => '2026-01-01',
        'name' => 'Tahun Baru Masehi',
        'year' => 2026,
    ]);
});
