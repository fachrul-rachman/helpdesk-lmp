<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\RefreshToken;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthService
{
    public function login(string $phoneNumber, string $password, ?string $ipAddress = null): array
    {
        $normalizedPhone = PhoneNumber::normalize($phoneNumber);

        /** @var User|null $user */
        $user = User::query()
            ->where('phone_number', $normalizedPhone)
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            throw new HttpException(401, 'Nomor HP atau password salah.');
        }

        if (!$user->is_active) {
            throw new HttpException(401, 'Akun Anda tidak aktif. Hubungi admin.');
        }

        $accessTtlSeconds = (int) config('auth.access_token_ttl_seconds', 900);
        $refreshTtlSeconds = (int) config('auth.refresh_token_ttl_seconds', 2_592_000);

        $accessToken = $this->issueAccessToken($user, $accessTtlSeconds);
        $refreshToken = $this->issueRefreshToken($user, $refreshTtlSeconds);

        $this->audit(
            action: 'auth.login',
            user: $user,
            subjectType: 'User',
            subjectId: $user->id,
            ipAddress: $ipAddress,
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtlSeconds,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'division_id' => $user->division_id,
            ],
        ];
    }

    public function refresh(string $refreshToken): array
    {
        $tokenHash = hash('sha256', $refreshToken);

        /** @var RefreshToken|null $record */
        $record = RefreshToken::query()
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->first();

        if (!$record || $record->expires_at->isPast()) {
            throw new HttpException(401, 'Refresh token tidak valid atau sudah kadaluarsa.');
        }

        /** @var User|null $user */
        $user = User::query()->find($record->user_id);

        if (!$user || !$user->is_active) {
            throw new HttpException(401, 'Refresh token tidak valid atau sudah kadaluarsa.');
        }

        $accessTtlSeconds = (int) config('auth.access_token_ttl_seconds', 900);
        $accessToken = $this->issueAccessToken($user, $accessTtlSeconds);

        return [
            'access_token' => $accessToken,
            'expires_in' => $accessTtlSeconds,
        ];
    }

    public function logout(User $user, string $refreshToken, ?string $ipAddress = null): void
    {
        $tokenHash = hash('sha256', $refreshToken);

        $updated = RefreshToken::query()
            ->where('user_id', $user->id)
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if ($updated < 1) {
            throw new HttpException(401, 'Refresh token tidak valid atau sudah kadaluarsa.');
        }

        $this->audit(
            action: 'auth.logout',
            user: $user,
            subjectType: 'User',
            subjectId: $user->id,
            ipAddress: $ipAddress,
        );
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword, ?string $ipAddress = null): void
    {
        if (!Hash::check($currentPassword, $user->password)) {
            throw new HttpException(422, 'Password lama tidak sesuai.');
        }

        $user->forceFill(['password' => Hash::make($newPassword)])->save();

        RefreshToken::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->audit(
            action: 'auth.password_changed',
            user: $user,
            subjectType: 'User',
            subjectId: $user->id,
            ipAddress: $ipAddress,
        );
    }

    public function forceLogout(User $actor, string $targetUserId, ?string $ipAddress = null): void
    {
        RefreshToken::query()
            ->where('user_id', $targetUserId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->audit(
            action: 'auth.force_logout',
            user: $actor,
            subjectType: 'User',
            subjectId: $targetUserId,
            ipAddress: $ipAddress,
        );
    }

    public function resetPassword(User $actor, string $targetUserId, string $newPassword, ?string $ipAddress = null): void
    {
        /** @var User|null $target */
        $target = User::query()->find($targetUserId);

        if (!$target) {
            throw new HttpException(404, 'User tidak ditemukan.');
        }

        $target->forceFill(['password' => Hash::make($newPassword)])->save();

        RefreshToken::query()
            ->where('user_id', $target->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->audit(
            action: 'admin.user.password_reset',
            user: $actor,
            subjectType: 'User',
            subjectId: $target->id,
            ipAddress: $ipAddress,
        );
    }

    private function issueAccessToken(User $user, int $accessTtlSeconds): string
    {
        $ttlMinutes = max(1, (int) ceil($accessTtlSeconds / 60));

        try {
            JWTAuth::factory()->setTTL($ttlMinutes);
            return JWTAuth::fromUser($user);
        } catch (JWTException $e) {
            throw new HttpException(500, 'Terjadi kesalahan pada server.');
        }
    }

    private function issueRefreshToken(User $user, int $refreshTtlSeconds): string
    {
        $plain = Str::random(64);

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => CarbonImmutable::now()->addSeconds($refreshTtlSeconds),
            'revoked_at' => null,
        ]);

        return $plain;
    }

    private function audit(
        string $action,
        User $user,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $ipAddress = null,
        array $payload = [],
    ): void {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload ?: null,
            'ip_address' => $ipAddress,
        ]);
    }
}
