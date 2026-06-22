<?php

use App\Jobs\FetchPublicHolidaysJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('holiday API request uses the provider co-id header', function () {
    putenv('HOLIDAY_API_URL=https://holidays.test/api');
    putenv('HOLIDAY_API_KEY=test-co-id');

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

    try {
        (new FetchPublicHolidaysJob)->handle();
    } finally {
        putenv('HOLIDAY_API_URL');
        putenv('HOLIDAY_API_KEY');
    }

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
