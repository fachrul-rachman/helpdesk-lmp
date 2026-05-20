<?php

use App\Models\RefreshToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function createUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Budi',
        'phone_number' => '6281234567890',
        'role' => 'pic',
        'division_id' => null,
        'password' => Hash::make('secret123'),
        'is_active' => true,
    ], $overrides));
}

test('login returns tokens', function () {
    createUser();

    $response = $this->postJson('/api/auth/login', [
        'phone_number' => '081234567890',
        'password' => 'secret123',
    ]);

    $response
        ->assertOk()
        ->assertJsonStructure([
            'access_token',
            'refresh_token',
            'token_type',
            'expires_in',
            'user' => ['id', 'name', 'role', 'division_id'],
        ]);

    expect(RefreshToken::count())->toBe(1);
});

test('login fails with wrong password', function () {
    createUser();

    $response = $this->postJson('/api/auth/login', [
        'phone_number' => '081234567890',
        'password' => 'wrong',
    ]);

    $response->assertStatus(401)->assertJson([
        'message' => 'Nomor HP atau password salah.',
    ]);
});

test('login fails for inactive user', function () {
    createUser(['is_active' => false]);

    $response = $this->postJson('/api/auth/login', [
        'phone_number' => '081234567890',
        'password' => 'secret123',
    ]);

    $response->assertStatus(401)->assertJson([
        'message' => 'Akun Anda tidak aktif. Hubungi admin.',
    ]);
});

test('refresh returns new access token', function () {
    createUser();

    $login = $this->postJson('/api/auth/login', [
        'phone_number' => '081234567890',
        'password' => 'secret123',
    ])->assertOk()->json();

    $response = $this->postJson('/api/auth/refresh', [
        'refresh_token' => $login['refresh_token'],
    ]);

    $response->assertOk()->assertJsonStructure(['access_token', 'expires_in']);
});

test('refresh fails with revoked token', function () {
    $user = createUser();

    $plain = 'revoked-refresh-token';
    RefreshToken::create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $plain),
        'expires_at' => CarbonImmutable::now()->addDay(),
        'revoked_at' => now(),
    ]);

    $response = $this->postJson('/api/auth/refresh', [
        'refresh_token' => $plain,
    ]);

    $response->assertStatus(401)->assertJson([
        'message' => 'Refresh token tidak valid atau sudah kadaluarsa.',
    ]);
});

test('logout revokes refresh token', function () {
    createUser();

    $login = $this->postJson('/api/auth/login', [
        'phone_number' => '081234567890',
        'password' => 'secret123',
    ])->assertOk()->json();

    $response = $this->postJson(
        '/api/auth/logout',
        ['refresh_token' => $login['refresh_token']],
        ['Authorization' => 'Bearer ' . $login['access_token']],
    );

    $response->assertOk()->assertJson(['message' => 'Berhasil logout.']);
    expect(RefreshToken::whereNotNull('revoked_at')->count())->toBe(1);
});

test('change password revokes all sessions', function () {
    $user = createUser();

    $login = $this->postJson('/api/auth/login', [
        'phone_number' => '081234567890',
        'password' => 'secret123',
    ])->assertOk()->json();

    RefreshToken::create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', 'another-refresh'),
        'expires_at' => CarbonImmutable::now()->addDay(),
        'revoked_at' => null,
    ]);

    $response = $this->postJson(
        '/api/auth/change-password',
        [
            'current_password' => 'secret123',
            'new_password' => 'newSecret456',
            'new_password_confirmation' => 'newSecret456',
        ],
        ['Authorization' => 'Bearer ' . $login['access_token']],
    );

    $response->assertOk()->assertJson(['message' => 'Password berhasil diubah.']);
    expect(RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0);
});

test('force logout by admin', function () {
    $admin = createUser([
        'role' => 'admin',
        'phone_number' => '628000000001',
    ]);
    $target = createUser([
        'phone_number' => '6289999999999',
        'password' => Hash::make('target1234'),
    ]);

    RefreshToken::create([
        'user_id' => $target->id,
        'token_hash' => hash('sha256', 't1'),
        'expires_at' => CarbonImmutable::now()->addDay(),
        'revoked_at' => null,
    ]);

    $adminLogin = $this->postJson('/api/auth/login', [
        'phone_number' => '628000000001',
        'password' => 'secret123',
    ])->assertOk()->json();

    $response = $this->postJson(
        '/api/auth/force-logout/' . $target->id,
        [],
        ['Authorization' => 'Bearer ' . $adminLogin['access_token']],
    );

    $response->assertOk()->assertJson(['message' => 'Semua sesi user berhasil dihentikan.']);
    expect(RefreshToken::where('user_id', $target->id)->whereNull('revoked_at')->count())->toBe(0);
});

