<?php

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function adminJwt(): array
{
    $admin = User::factory()->create([
        'role' => 'admin',
        'phone_number' => '628' . fake()->unique()->numerify(str_repeat('#', 9)),
        'password' => Hash::make('admin123456'),
        'is_active' => true,
        'division_id' => null,
    ]);

    return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($admin)];
}

test('admin can get settings', function () {
    AppSetting::upsert([
        ['key' => 'sla_fr_duration_minutes', 'value' => '5'],
        ['key' => 'sla_fr_reminder_minutes', 'value' => '3'],
    ], ['key'], ['value']);

    $response = $this->getJson('/api/admin/settings', adminJwt());
    $response->assertOk()->assertJson([
        'sla_fr_duration_minutes' => 5,
        'sla_fr_reminder_minutes' => 3,
    ]);
});

test('admin can update settings', function () {
    $response = $this->putJson('/api/admin/settings', [
        'sla_fr_duration_minutes' => 10,
        'sla_fr_reminder_minutes' => 3,
    ], adminJwt());

    $response->assertOk();
    expect(AppSetting::query()->where('key', 'sla_fr_duration_minutes')->value('value'))->toBe('10');
});

test('settings reminder must be less than duration', function () {
    $response = $this->putJson('/api/admin/settings', [
        'sla_fr_duration_minutes' => 5,
        'sla_fr_reminder_minutes' => 5,
    ], adminJwt());

    $response->assertStatus(422)->assertJson([
        'message' => 'Threshold reminder harus lebih kecil dari durasi SLA FR.',
    ]);
});
